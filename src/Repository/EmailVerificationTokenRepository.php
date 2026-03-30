<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailVerificationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailVerificationToken>
 */
final class EmailVerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationToken::class);
    }

    public function findUsableByHash(string $tokenHash): ?EmailVerificationToken
    {
        $token = $this->findOneBy(['tokenHash' => $tokenHash]);

        if ($token === null) {
            return null;
        }

        return $token->isUsable(new \DateTimeImmutable()) ? $token : null;
    }
}
