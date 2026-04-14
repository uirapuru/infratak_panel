<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Server;
use App\Entity\ServerOperationLog;
use App\Entity\ServerSubscription;
use App\Entity\User;
use App\Enum\ServerStatus;
use App\Message\StartServerMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SubscriptionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function purchase(Server $server, User $user, int $days): ServerSubscription
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Subscription must be purchased for at least 1 day.');
        }

        if ($server->getStatus() === ServerStatus::DELETED) {
            throw new \InvalidArgumentException('Cannot renew a deleted server. Create a new server instead.');
        }

        $owner = $server->getOwner();
        if ($owner !== null && $owner->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('This server belongs to a different user.');
        }

        $now = new \DateTimeImmutable();
        $startsAt = $server->getSubscriptionPaidUntil();
        if ($startsAt === null || $startsAt < $now) {
            $startsAt = $now;
        }

        $expiresAt = $startsAt->modify(sprintf('+%d days', $days));
        $subscription = new ServerSubscription($server, $user, $days, $startsAt, $expiresAt);

        $server
            ->setOwner($owner ?? $user)
            ->setSubscriptionPaidUntil($expiresAt)
            ->setSubscriptionExpiredAt(null)
            ->setSubscriptionTerminationQueuedAt(null)
            ->setLastError(null);

        $this->entityManager->persist($subscription);
        $this->entityManager->persist(new ServerOperationLog(
            $server,
            'info',
            'Subscription purchased.',
            $server->getStatus()->value,
            $server->getStep()->value,
            [
                'days' => $days,
                'amountGrossPln' => $subscription->getAmountGrossPln(),
                'currency' => $subscription->getCurrency(),
                'startsAt' => $startsAt->format(DATE_ATOM),
                'expiresAt' => $expiresAt->format(DATE_ATOM),
                'userId' => $user->getId(),
            ],
        ));

        $this->entityManager->flush();

        if ($server->getStatus() === ServerStatus::STOPPED && $server->getAwsInstanceId() !== null) {
            $this->messageBus->dispatch(new StartServerMessage($server->getId()));
        }

        return $subscription;
    }
}
