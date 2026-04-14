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
            ->setParameter('deletedStatus', ServerStatus::DELETED->value);

        if ($excludeId !== null && $excludeId !== '') {
            $queryBuilder
                ->andWhere('server.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Returns servers eligible for AWS state sync: have an instance ID and are in a stable status.
     *
     * @return Server[]
     */
    public function findSyncable(): array
    {
        return $this->createQueryBuilder('server')
            ->andWhere('server.awsInstanceId IS NOT NULL')
            ->andWhere('server.status IN (:statuses)')
            ->setParameter('statuses', [ServerStatus::READY->value, ServerStatus::STOPPED->value])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Server[]
     */
    public function findWithExpiredSubscriptionPendingMark(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('server')
            ->andWhere('server.status IN (:statuses)')
            ->andWhere('server.subscriptionPaidUntil IS NOT NULL')
            ->andWhere('server.subscriptionPaidUntil < :now')
            ->andWhere('server.subscriptionExpiredAt IS NULL')
            ->setParameter('statuses', [ServerStatus::READY->value, ServerStatus::STOPPED->value])
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Server[]
     */
    public function findExpiredBeyondGracePeriod(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('server')
            ->andWhere('server.status != :deletedStatus')
            ->andWhere('server.subscriptionExpiredAt IS NOT NULL')
            ->andWhere('server.subscriptionExpiredAt <= :cutoff')
            ->andWhere('server.subscriptionTerminationQueuedAt IS NULL')
            ->setParameter('deletedStatus', ServerStatus::DELETED->value)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
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
