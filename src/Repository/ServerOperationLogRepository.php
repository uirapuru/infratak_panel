<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServerOperationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServerOperationLog>
 */
final class ServerOperationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServerOperationLog::class);
    }

    /**
     * Returns the most recent log entries for a given server, newest first.
     *
     * @return ServerOperationLog[]
     */
    public function findRecentForServer(string $serverId, int $limit = 5): array
    {
        return $this->createQueryBuilder('log')
            ->andWhere('log.server = :serverId')
            ->setParameter('serverId', $serverId)
            ->orderBy('log.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all log entries for a given server, newest first.
     *
     * @return ServerOperationLog[]
     */
    public function findAllForServer(string $serverId): array
    {
        return $this->createQueryBuilder('log')
            ->andWhere('log.server = :serverId')
            ->setParameter('serverId', $serverId)
            ->orderBy('log.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
