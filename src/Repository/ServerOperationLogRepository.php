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
}
