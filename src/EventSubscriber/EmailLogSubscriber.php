<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\EventSubscriber;

use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\Service\EmailLogWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Logs every e-mail sent through Symfony Mailer, synchronously or through
 * Messenger, from Mailer and Messenger events:
 *
 * - SendMessageToTransportsEvent: a SendEmailMessage is queued; the entry is
 *   created as queued and the message is tagged with a correlation id.
 * - MessageEvent (not queued): a transport attempt starts; the entry is created if
 *   the message was not queued.
 * - SentMessageEvent / FailedMessageEvent: the attempt is recorded.
 * - WorkerMessageFailedEvent: a worker's failed attempt is recorded here, after the
 *   handler is done (a doctrine_transaction middleware has rolled back by then),
 *   whether the transport or something before it failed; a message Messenger
 *   retries goes back to queued.
 *
 * The type and reference headers are always removed before delivery. Messages
 * that are not {@see Message} instances (bare RawMessage) cannot be tagged and
 * are not logged. A logging error is logged and never stops the e-mail.
 */
final class EmailLogSubscriber implements EventSubscriberInterface
{
    /** Correlation id of the SendEmailMessage the worker is handling. */
    private ?string $workerMessageId = null;

    /** Transport error of that message, recorded once the worker reports the failure. */
    private ?string $workerTransportError = null;

    public function __construct(
        private EmailLogWriter $writer,
        private bool $enabled,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSendToTransports',
            // After the body is rendered (MessageListener, 0), before DKIM/S-MIME signing (-128).
            MessageEvent::class => ['onMessage', -64],
            SentMessageEvent::class => 'onSent',
            FailedMessageEvent::class => 'onFailed',
            WorkerMessageReceivedEvent::class => 'onWorkerReceived',
            WorkerMessageHandledEvent::class => 'onWorkerHandled',
            // After the retry listener (100) decided whether the message is retried.
            WorkerMessageFailedEvent::class => 'onWorkerFailed',
        ];
    }

    public function onSendToTransports(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $sendEmail = $envelope->getMessage();
        if (!$this->enabled || !$sendEmail instanceof SendEmailMessage || !$sendEmail->getMessage() instanceof Message) {
            return;
        }

        $message = $sendEmail->getMessage();
        $id = $this->correlationId($message);
        if ($id === null) {
            // Tag a copy so the caller's message object stays untouched.
            $message = clone $message;
            $id = $this->writer->newCorrelationId();
            $message->getHeaders()->addTextHeader(SystemEmailLog::HEADER_ID, $id);
            $event->setEnvelope(new Envelope(
                new SendEmailMessage($message, $sendEmail->getEnvelope()),
                array_merge(...array_values($envelope->all())),
            ));
        }

        $type = $this->header($message, SystemEmailLog::HEADER_TYPE);
        $reference = $this->header($message, SystemEmailLog::HEADER_REFERENCE);
        $this->guard(fn () => $this->writer->open($id, $message, $type, $reference));
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if ($event->isQueued() || !$message instanceof Message) {
            return;
        }

        $type = $this->header($message, SystemEmailLog::HEADER_TYPE);
        $reference = $this->header($message, SystemEmailLog::HEADER_REFERENCE);
        $message->getHeaders()->remove(SystemEmailLog::HEADER_TYPE);
        $message->getHeaders()->remove(SystemEmailLog::HEADER_REFERENCE);

        if (!$this->enabled) {
            return;
        }

        $id = $this->correlationId($message);
        if ($id === null) {
            $id = $this->writer->newCorrelationId();
            $message->getHeaders()->addTextHeader(SystemEmailLog::HEADER_ID, $id);
        }

        $this->guard(fn () => $this->writer->startAttempt($id, $message, $type, $reference));
    }

    public function onSent(SentMessageEvent $event): void
    {
        $sent = $event->getMessage();
        $id = $this->enabled ? $this->correlationId($sent->getOriginalMessage()) : null;
        if ($id === null) {
            return;
        }

        $this->guard(fn () => $this->writer->recordSent($id, $sent->getMessageId()));
    }

    public function onFailed(FailedMessageEvent $event): void
    {
        $id = $this->enabled ? $this->correlationId($event->getMessage()) : null;
        if ($id === null) {
            return;
        }

        if ($id === $this->workerMessageId) {
            $this->workerTransportError = $event->getError()->getMessage();

            return;
        }

        $this->guard(fn () => $this->writer->recordFailure($id, $event->getError()->getMessage()));
    }

    public function onWorkerReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->workerMessageId = $this->workerMessageId($event->getEnvelope());
        $this->workerTransportError = null;
    }

    public function onWorkerHandled(WorkerMessageHandledEvent $event): void
    {
        $this->workerMessageId = null;
        $this->workerTransportError = null;
    }

    public function onWorkerFailed(WorkerMessageFailedEvent $event): void
    {
        $id = $this->workerMessageId($event->getEnvelope());
        $transportError = $id !== null && $id === $this->workerMessageId ? $this->workerTransportError : null;
        $this->workerMessageId = null;
        $this->workerTransportError = null;
        if ($id === null) {
            return;
        }

        $error = $event->getThrowable();
        if ($error instanceof HandlerFailedException) {
            $error = $error->getPrevious() ?? $error;
        }

        $this->guard(fn () => $this->writer->recordFailure($id, $transportError ?? $error->getMessage(), $event->willRetry()));
    }

    private function workerMessageId(Envelope $envelope): ?string
    {
        $sendEmail = $envelope->getMessage();

        return $this->enabled && $sendEmail instanceof SendEmailMessage ? $this->correlationId($sendEmail->getMessage()) : null;
    }

    private function correlationId(RawMessage $message): ?string
    {
        return $message instanceof Message ? $this->header($message, SystemEmailLog::HEADER_ID) : null;
    }

    private function header(Message $message, string $name): ?string
    {
        $value = $message->getHeaders()->getHeaderBody($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function guard(callable $write): void
    {
        try {
            $write();
        } catch (\Exception $e) {
            $this->logger?->error('Could not write the e-mail log: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
