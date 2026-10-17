<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;
use Hexis\SystemEmailBundle\Enum\EmailLogStatus;

/**
 * Read side of the outgoing e-mail log, for an application's log screen.
 *
 * @extends ServiceEntityRepository<SystemEmailLog>
 */
class SystemEmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemEmailLog::class);
    }

    public function findOneByCorrelationId(string $correlationId): ?SystemEmailLog
    {
        return $this->findOneBy(['correlationId' => $correlationId]);
    }

    /**
     * Log entries, newest first, one page at a time. Every filter is optional.
     *
     * @param string|null             $recipient part of a To, Cc or Bcc address
     * @param \DateTimeImmutable|null $since     created at or after (inclusive)
     * @param \DateTimeImmutable|null $until     created before (exclusive)
     * @param int                     $page      1-based
     *
     * @return Paginator<SystemEmailLog> countable (total matches) and iterable (this page)
     */
    public function search(
        ?EmailLogStatus $status = null,
        ?string $type = null,
        ?string $recipient = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        int $page = 1,
        int $perPage = 50,
    ): Paginator {
        $perPage = max(1, $perPage);

        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult((max(1, $page) - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($status !== null) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status->value);
        }

        if ($type !== null && $type !== '') {
            $qb->andWhere('l.type = :type')->setParameter('type', $type);
        }

        if ($recipient !== null && $recipient !== '') {
            // _ and % are literal address characters here; ! escapes them on every platform.
            $literal = strtr($recipient, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $qb->andWhere("l.recipients LIKE :recipient ESCAPE '!'")->setParameter('recipient', '%' . $literal . '%');
        }

        if ($since !== null) {
            $qb->andWhere('l.createdAt >= :since')->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        if ($until !== null) {
            $qb->andWhere('l.createdAt < :until')->setParameter('until', $until, Types::DATETIME_IMMUTABLE);
        }

        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Entries that resend the given one, oldest first.
     *
     * @return SystemEmailLog[]
     */
    public function findResends(SystemEmailLog $original): array
    {
        return $this->findBy(['resentFrom' => $original], ['id' => 'ASC']);
    }
}
