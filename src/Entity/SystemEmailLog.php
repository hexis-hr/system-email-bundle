<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Hexis\SystemEmailBundle\Enum\EmailLogStatus;
use Hexis\SystemEmailBundle\Repository\SystemEmailLogRepository;
use Symfony\Component\Mime\RawMessage;

/**
 * One outgoing e-mail and its delivery attempts.
 *
 * Rows are written by {@see \Hexis\SystemEmailBundle\Service\EmailLogWriter}
 * through DBAL (never through the ORM unit of work, so logging never flushes the
 * application's pending changes); the entity is the read model. The table name
 * is configurable (system_email.mail_log.table_name).
 */
#[ORM\Entity(repositoryClass: SystemEmailLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'system_email_log')]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['email_type'])]
#[ORM\Index(columns: ['created_at'])]
class SystemEmailLog
{
    /** Correlates the queueing and every delivery attempt of one message. Kept on the delivered message. */
    public const HEADER_ID = 'X-System-Email-Id';

    /** Optional: the system e-mail type key. Logged, then removed before delivery. */
    public const HEADER_TYPE = 'X-System-Email-Type';

    /** Optional: free-form reference to the record the e-mail is about. Logged, then removed before delivery. */
    public const HEADER_REFERENCE = 'X-System-Email-Reference';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'correlation_id', type: 'string', length: 32, unique: true)]
    private string $correlationId = '';

    #[ORM\Column(name: 'email_type', type: 'string', length: 100, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(name: 'reference', type: 'string', length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'sender', type: 'string', length: 255, nullable: true)]
    private ?string $sender = null;

    /** To, Cc and Bcc addresses, comma-separated. */
    #[ORM\Column(name: 'recipients', type: 'text')]
    private string $recipients = '';

    #[ORM\Column(name: 'subject', type: 'text', nullable: true)]
    private ?string $subject = null;

    /** Backing value of {@see EmailLogStatus}; QUEUED as a literal, PHP 8.1 cannot read ->value in a default. */
    #[ORM\Column(name: 'status', type: 'string', length: 10)]
    private string $status = 'queued';

    #[ORM\Column(name: 'attempt_count', type: 'integer', options: ['default' => 0])]
    private int $attemptCount = 0;

    /** @var list<array{at: string, status: string, error: string|null}> */
    #[ORM\Column(name: 'attempts', type: 'json')]
    private array $attempts = [];

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** Id the transport returned for the successful attempt (SentMessage::getMessageId()). */
    #[ORM\Column(name: 'transport_message_id', type: 'string', length: 255, nullable: true)]
    private ?string $transportMessageId = null;

    /** Base64 of the serialized message (headers, body, attachments), used to show and resend it. */
    #[ORM\Column(name: 'message', type: 'text', nullable: true)]
    private ?string $storedMessage = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'resent_from_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SystemEmailLog $resentFrom = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getSender(): ?string
    {
        return $this->sender;
    }

    public function getRecipients(): string
    {
        return $this->recipients;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getStatus(): EmailLogStatus
    {
        return EmailLogStatus::from($this->status);
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    /**
     * Every delivery attempt, oldest first; `at` is an ISO 8601 timestamp.
     *
     * @return list<array{at: string, status: string, error: string|null}>
     */
    public function getAttempts(): array
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getTransportMessageId(): ?string
    {
        return $this->transportMessageId;
    }

    /**
     * The message as it was handed to the transport (rendered, before signing),
     * or null when it could not be stored.
     */
    public function getStoredMessage(): ?RawMessage
    {
        if ($this->storedMessage === null) {
            return null;
        }

        $serialized = base64_decode($this->storedMessage, true);
        if ($serialized === false) {
            return null;
        }

        $message = unserialize($serialized);

        return $message instanceof RawMessage ? $message : null;
    }

    /** The entry this one resends, if it is a resend. */
    public function getResentFrom(): ?SystemEmailLog
    {
        return $this->resentFrom;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
}
