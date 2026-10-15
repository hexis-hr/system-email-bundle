<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Service;

use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\Model\Attachment;
use Hexis\SystemEmailBundle\Model\Recipient;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Convenience sender that resolves a type's recipients, honours the per-type
 * enabled kill-switch, and delivers via Symfony Mailer (synchronously, or via
 * Messenger when a bus is available and async is enabled).
 *
 * The subject and HTML body are supplied by the caller: this bundle manages
 * recipients and the on/off switch, not message content.
 */
final class SystemEmailMailer
{
    public function __construct(
        private SystemEmailManager $manager,
        private MailerInterface $mailer,
        private ?MessageBusInterface $bus = null,
        private bool $async = true,
        private ?string $defaultFromAddress = null,
        private ?string $defaultFromName = null,
    ) {
    }

    /**
     * Build and deliver mail for a type.
     *
     * @param Attachment[]                                       $attachments
     * @param array{address?: string|null, name?: string|null}  $fromOverride
     * @param Recipient[]                                        $extraRecipients additional ad-hoc recipients (e.g. the acting user)
     * @param object[]                                           $stamps          Messenger stamps used on the async path
     * @param string|null                                        $reference       record the e-mail is about, shown in the e-mail log (e.g. "invoice:42")
     *
     * @return bool true when sent/queued; false when skipped (type disabled or no recipients)
     */
    public function send(
        string $key,
        string $subject,
        string $htmlBody,
        array $attachments = [],
        array $fromOverride = [],
        array $extraRecipients = [],
        array $stamps = [],
        ?string $reference = null,
    ): bool {
        if (!$this->manager->isEnabled($key)) {
            return false;
        }

        $recipients = array_merge($this->manager->getRecipients($key), $extraRecipients);
        if ($recipients === []) {
            return false;
        }

        $email = (new Email())
            ->subject($subject)
            ->html($this->prepareEmailBody($htmlBody));

        $this->applyFrom($email, $fromOverride);
        $this->applyRecipients($email, $recipients);
        $this->applyAttachments($email, $attachments);

        // Read by the e-mail log and removed before delivery.
        $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_TYPE, $key);
        if ($reference !== null && $reference !== '') {
            $email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_REFERENCE, $reference);
        }

        if ($this->async && $this->bus !== null) {
            $this->bus->dispatch(new SendEmailMessage($email), $stamps);
        } else {
            $this->mailer->send($email);
        }

        return true;
    }

    /**
     * @param array{address?: string|null, name?: string|null} $fromOverride
     */
    private function applyFrom(Email $email, array $fromOverride): void
    {
        $address = $fromOverride['address'] ?? $this->defaultFromAddress;
        if ($address === null || $address === '') {
            // No explicit sender: rely on a globally configured mailer envelope/from.
            return;
        }

        $name = $fromOverride['name'] ?? $this->defaultFromName;
        $email->from($name !== null && $name !== '' ? new Address($address, $name) : new Address($address));
    }

    /**
     * Apply TO/CC/BCC. Guarantees at least one TO: if none is flagged, the first
     * recipient is promoted to TO (matching the established MIS behaviour).
     *
     * @param Recipient[] $recipients
     */
    private function applyRecipients(Email $email, array $recipients): void
    {
        $hasTo = false;
        foreach ($recipients as $recipient) {
            if ($recipient->isTo()) {
                $hasTo = true;
                break;
            }
        }

        $defaultToAssigned = false;
        foreach ($recipients as $recipient) {
            $address = new Address($recipient->getEmail(), $recipient->getName());

            if (!$hasTo && !$defaultToAssigned) {
                $defaultToAssigned = true;
                $email->addTo($address);
                continue;
            }

            if ($recipient->isCC()) {
                $email->addCc($address);
                continue;
            }

            if ($recipient->isBCC()) {
                $email->addBcc($address);
                continue;
            }

            $email->addTo($address);
        }
    }

    /**
     * @param Attachment[] $attachments
     */
    private function applyAttachments(Email $email, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->getContent() === null) {
                continue;
            }

            $email->attach($attachment->getContent(), $attachment->getTitle(), $attachment->getMimeType());
        }
    }

    private function prepareEmailBody(string $content): string
    {
        $clean = preg_replace('/<style.*?<\/style>/si', '', $content);
        $clean = preg_replace('/<br.*?>/si', '<br/>', $clean ?? $content);

        return $clean ?? $content;
    }
}
