<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Service;

use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\Repository\SystemEmailLogRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

/**
 * Sends a logged e-mail again. The resend is a new log entry linked to the
 * original (getResentFrom()); the original entry is left as it is.
 */
final class EmailResender
{
    public function __construct(
        private EmailLogWriter $writer,
        private SystemEmailLogRepository $repository,
        private MailerInterface $mailer,
        private bool $enabled = true,
    ) {
    }

    /**
     * @param list<Address|string>|null $to corrected recipients: they replace To, and Cc and Bcc are
     *                                      dropped; null resends to the original recipients
     *
     * @return SystemEmailLog the new entry: queued when mail goes through a Messenger queue,
     *                        otherwise sent or failed (a transport failure is not thrown, it is logged)
     */
    public function resend(SystemEmailLog $entry, ?array $to = null): SystemEmailLog
    {
        if (!$this->enabled) {
            throw new \LogicException('The e-mail log is disabled (system_email.mail_log.enabled).');
        }

        $message = $entry->getStoredMessage();
        if (!$message instanceof Message) {
            throw new \LogicException(sprintf('E-mail log entry #%d has no stored message to resend.', (int) $entry->getId()));
        }

        if ($to !== null) {
            if ($to === []) {
                throw new \InvalidArgumentException('Give at least one corrected recipient.');
            }

            if (!$message instanceof Email) {
                throw new \LogicException(sprintf('The recipients of e-mail log entry #%d cannot be changed.', (int) $entry->getId()));
            }

            $message->to(...$to);
            $message->getHeaders()->remove('Cc');
            $message->getHeaders()->remove('Bcc');
        }

        if ($message instanceof Email) {
            foreach ($message->getAttachments() as $attachment) {
                // An attachment stored by file path throws here when the file is gone, before anything is logged.
                $attachment->getBody();
            }
        }

        $id = $this->writer->newCorrelationId();
        $message->getHeaders()->remove(SystemEmailLog::HEADER_ID);
        $message->getHeaders()->addTextHeader(SystemEmailLog::HEADER_ID, $id);
        $this->writer->open($id, $message, $entry->getType(), $entry->getReference(), $entry->getId());

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            // Recorded on the new entry as a failed attempt.
        }

        return $this->repository->findOneByCorrelationId($id)
            ?? throw new \LogicException(sprintf('The resend of e-mail log entry #%d was not logged.', (int) $entry->getId()));
    }
}
