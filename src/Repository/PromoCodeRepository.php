<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PromoCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromoCode>
 */
final class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    /**
     * Finds a currently valid code (case-insensitive).
     * Does NOT check maxUses vs usedCount here — call isValid() on the result.
     */
    public function findValidByCode(string $code): ?PromoCode
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('p')
            ->where('UPPER(p.code) = :code')
            ->andWhere('p.isActive = true')
            ->andWhere('p.expiresAt IS NULL OR p.expiresAt > :now')
            ->setParameter('code', strtoupper(trim($code)))
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
