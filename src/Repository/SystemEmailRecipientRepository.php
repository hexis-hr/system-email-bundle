<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Hexis\SystemEmailBundle\Entity\SystemEmailRecipient;

/**
 * @extends ServiceEntityRepository<SystemEmailRecipient>
 */
class SystemEmailRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemEmailRecipient::class);
    }

    /**
     * Active recipients for a type key, ordered by address.
     *
     * @return SystemEmailRecipient[]
     */
    public function findActiveByTypeKey(string $key): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.type', 't')
            ->andWhere('t.key = :key')
            ->andWhere('r.active = :active')
            ->setParameter('key', $key)
            ->setParameter('active', true)
            ->orderBy('r.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active recipient addresses for a type key.
     *
     * @return string[]
     */
    public function getActiveEmailsByTypeKey(string $key): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.email')
            ->innerJoin('r.type', 't')
            ->andWhere('t.key = :key')
            ->andWhere('r.active = :active')
            ->setParameter('key', $key)
            ->setParameter('active', true)
            ->orderBy('r.email', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'email');
    }
}
