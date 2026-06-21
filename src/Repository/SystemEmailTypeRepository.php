<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;

/**
 * @extends ServiceEntityRepository<SystemEmailType>
 */
class SystemEmailTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemEmailType::class);
    }

    public function findOneByKey(string $key): ?SystemEmailType
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * @return SystemEmailType[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['label' => 'ASC']);
    }
}
