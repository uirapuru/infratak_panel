<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Server;
use App\Enum\ServerStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Server>
 */
final class ServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Server::class);
    }

    public function hasActiveServerWithName(string $name, ?string $excludeId = null): bool
    {
        $queryBuilder = $this->createQueryBuilder('server')
            ->select('COUNT(server.id)')
            ->andWhere('server.name = :name')
            ->andWhere('server.status != :deletedStatus')
            ->setParameter('name', $name)
            ->setParameter('deletedStatus', ServerStatus::DELETED);

        if ($excludeId !== null && $excludeId !== '') {
            $queryBuilder
                ->andWhere('server.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }

    public function countInProvisioning(): int
    {
        return (int) $this->createQueryBuilder('server')
            ->select('COUNT(server.id)')
            ->andWhere('server.status != :readyStatus')
            ->andWhere('server.status != :failedStatus')
            ->andWhere('server.status != :deletedStatus')
            ->andWhere('server.status != :stoppedStatus')
            ->setParameter('readyStatus', ServerStatus::READY->value)
            ->setParameter('failedStatus', ServerStatus::FAILED->value)
            ->setParameter('deletedStatus', ServerStatus::DELETED->value)
            ->setParameter('stoppedStatus', ServerStatus::STOPPED->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
