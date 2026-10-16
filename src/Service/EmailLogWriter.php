<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\Enum\EmailLogStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Message;

/**
 * Writes the outgoing e-mail log through DBAL, keyed by the correlation id the
 * message carries in its {@see SystemEmailLog::HEADER_ID} header.
 *
 * DBAL rather than the ORM: a flush from a mailer listener would also flush the
 * application's pending changes. The statements join whatever transaction is open
 * on the connection, like the Doctrine Messenger transport does, in a savepoint:
 * a failed log write is rolled back alone and does not leave that transaction
 * aborted (PostgreSQL). Without an open transaction each write has its own.
 */
final class EmailLogWriter
{
    private const SAVEPOINT = 'system_email_log';

    private const TYPES = [
        'resent_from_id' => Types::INTEGER,
        'attempt_count' => Types::INTEGER,
        'attempts' => Types::JSON,
        'created_at' => Types::DATETIME_IMMUTABLE,
        'updated_at' => Types::DATETIME_IMMUTABLE,
        'sent_at' => Types::DATETIME_IMMUTABLE,
    ];

    public function __construct(
        private ManagerRegistry $registry,
        private string $tableName,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function newCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Create the entry of a message that is being queued or resent, unless it exists.
     */
    public function open(
        string $correlationId,
        Message $message,
        ?string $type,
        ?string $reference,
        ?int $resentFromId = null,
    ): void {
        $this->atomically(function () use ($correlationId, $message, $type, $reference, $resentFromId): void {
            if ($this->find($correlationId) === null) {
                $this->insert($correlationId, $message, $type, $reference, $resentFromId);
            }
        });
    }

    /**
     * Called right before a transport attempt. Creates the entry of a message sent
     * without a queue; before every attempt of a queued message, replaces the
     * sender, recipients, subject and stored message with the final (rendered) ones
     * (a worker transaction that failed rolled back the earlier replacement).
     */
    public function startAttempt(string $correlationId, Message $message, ?string $type, ?string $reference): void
    {
        $this->atomically(function () use ($correlationId, $message, $type, $reference): void {
            if ($this->find($correlationId) === null) {
                $this->insert($correlationId, $message, $type, $reference, null);

                return;
            }

            $this->update($correlationId, $this->describe($message) + ['updated_at' => new \DateTimeImmutable()]);
        });
    }

    public function recordSent(string $correlationId, ?string $transportMessageId): void
    {
        $now = new \DateTimeImmutable();

        $this->recordAttempt($correlationId, EmailLogStatus::SENT, null, $now, [
            'status' => EmailLogStatus::SENT->value,
            'sent_at' => $now,
            'transport_message_id' => $this->limit($transportMessageId, 255),
        ]);
    }

    /**
     * @param bool $willRetry the entry goes back to queued while Messenger retries the message
     */
    public function recordFailure(string $correlationId, string $error, bool $willRetry = false): void
    {
        $status = $willRetry ? EmailLogStatus::QUEUED : EmailLogStatus::FAILED;

        $this->recordAttempt($correlationId, EmailLogStatus::FAILED, $error, new \DateTimeImmutable(), [
            'status' => $status->value,
            'last_error' => $error,
        ]);
    }

    /**
     * Delete the entries created before the given moment.
     *
     * @return int the number of deleted entries
     */
    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->connection()->executeStatement(
            sprintf('DELETE FROM %s WHERE created_at < ?', $this->tableName),
            [$before],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordAttempt(
        string $correlationId,
        EmailLogStatus $outcome,
        ?string $error,
        \DateTimeImmutable $now,
        array $data,
    ): void {
        $this->atomically(function () use ($correlationId, $outcome, $error, $now, $data): void {
            // Counting in SQL locks the row until the attempt is appended, so concurrent attempts are not lost.
            $counted = $this->connection()->executeStatement(
                sprintf('UPDATE %s SET attempt_count = attempt_count + 1 WHERE correlation_id = ?', $this->tableName),
                [$correlationId],
            );
            if ($counted === 0) {
                $this->logger?->warning('No e-mail log entry for correlation id {id}; attempt not recorded.', ['id' => $correlationId]);

                return;
            }

            $attempts = json_decode((string) ($this->find($correlationId)['attempts'] ?? ''), true);
            $attempts = \is_array($attempts) ? $attempts : [];
            $attempts[] = ['at' => $now->format(\DATE_ATOM), 'status' => $outcome->value, 'error' => $error];

            $this->update($correlationId, $data + ['attempts' => $attempts, 'updated_at' => $now]);
        });
    }

    /**
     * Runs a log write in a savepoint of the open transaction, or in a transaction of its own.
     */
    private function atomically(\Closure $write): void
    {
        $connection = $this->connection();
        if (!$connection->isTransactionActive()) {
            $connection->transactional($write);

            return;
        }

        $connection->createSavepoint(self::SAVEPOINT);
        try {
            $write();
        } catch (\Throwable $e) {
            $connection->rollbackSavepoint(self::SAVEPOINT);

            throw $e;
        }
        $connection->releaseSavepoint(self::SAVEPOINT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $correlationId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            sprintf('SELECT attempts FROM %s WHERE correlation_id = ?', $this->tableName),
            [$correlationId],
        );

        return $row === false ? null : $row;
    }

    private function insert(
        string $correlationId,
        Message $message,
        ?string $type,
        ?string $reference,
        ?int $resentFromId,
    ): void {
        $now = new \DateTimeImmutable();

        $this->connection()->insert($this->tableName, [
            'correlation_id' => $correlationId,
            'email_type' => $this->limit($type, 100),
            'reference' => $this->limit($reference, 255),
            'status' => EmailLogStatus::QUEUED->value,
            'attempt_count' => 0,
            'attempts' => [],
            'resent_from_id' => $resentFromId,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $this->describe($message), self::TYPES);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(string $correlationId, array $data): void
    {
        $this->connection()->update($this->tableName, $data, ['correlation_id' => $correlationId], self::TYPES);
    }

    /**
     * @return array{sender: string|null, recipients: string, subject: string|null, message: string|null}
     */
    private function describe(Message $message): array
    {
        $headers = $message->getHeaders();
        $subject = $headers->getHeaderBody('Subject');

        return [
            'sender' => $this->limit($this->addresses($headers, 'From', true)[0] ?? null, 255),
            'recipients' => implode(', ', array_unique(array_merge(
                $this->addresses($headers, 'To'),
                $this->addresses($headers, 'Cc'),
                $this->addresses($headers, 'Bcc'),
            ))),
            'subject' => \is_string($subject) ? $subject : null,
            'message' => $this->serialize($message),
        ];
    }

    /**
     * @return list<string>
     */
    private function addresses(Headers $headers, string $name, bool $withName = false): array
    {
        $header = $headers->get($name);
        if (!$header instanceof MailboxListHeader) {
            return [];
        }

        return array_values(array_map(
            static fn (Address $address): string => $withName && $address->getName() !== ''
                ? sprintf('%s <%s>', $address->getName(), $address->getAddress())
                : $address->getAddress(),
            $header->getAddresses(),
        ));
    }

    private function serialize(Message $message): ?string
    {
        try {
            return base64_encode(serialize($message));
        } catch (\Exception $e) {
            $this->logger?->warning('E-mail not stored in the e-mail log, it cannot be resent: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function connection(): Connection
    {
        $manager = $this->registry->getManagerForClass(SystemEmailLog::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(sprintf('No Doctrine entity manager manages %s.', SystemEmailLog::class));
        }

        return $manager->getConnection();
    }
}
