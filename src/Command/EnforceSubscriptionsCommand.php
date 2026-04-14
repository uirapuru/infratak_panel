<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServerOperationLog;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\DeleteServerMessage;
use App\Message\ManualStopServerMessage;
use App\Repository\ServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:subscriptions:enforce',
    description: 'Stop expired paid servers and queue cleanup after the 30-day grace period.',
)]
final class EnforceSubscriptionsCommand extends Command
{
    private const string GRACE_PERIOD = '30 days';

    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $stopServerIds = [];
        $cleanupServerIds = [];

        foreach ($this->serverRepository->findWithExpiredSubscriptionPendingMark($now) as $server) {
            $server->setSubscriptionExpiredAt($now);
            $shouldStop = $server->getStatus() === ServerStatus::READY;
            $this->entityManager->persist(new ServerOperationLog(
                $server,
                'warning',
                $shouldStop ? 'Subscription expired; AWS stop queued.' : 'Subscription expired while server was already stopped.',
                $server->getStatus()->value,
                $server->getStep()->value,
                [
                    'paidUntil' => $server->getSubscriptionPaidUntil()?->format(DATE_ATOM),
                    'gracePeriodDays' => 30,
                ],
            ));
            if ($shouldStop) {
                $stopServerIds[] = $server->getId();
            }
        }

        $cutoff = $now->modify('-'.self::GRACE_PERIOD);
        foreach ($this->serverRepository->findExpiredBeyondGracePeriod($cutoff) as $server) {
            $server
                ->setStep(ServerStep::CLEANUP)
                ->setSubscriptionTerminationQueuedAt($now);

            $this->entityManager->persist(new ServerOperationLog(
                $server,
                'warning',
                'Subscription grace period exceeded; AWS cleanup queued.',
                $server->getStatus()->value,
                $server->getStep()->value,
                [
                    'expiredAt' => $server->getSubscriptionExpiredAt()?->format(DATE_ATOM),
                    'gracePeriodDays' => 30,
                ],
            ));
            $cleanupServerIds[] = $server->getId();
        }

        $this->entityManager->flush();

        foreach ($stopServerIds as $serverId) {
            $this->messageBus->dispatch(new ManualStopServerMessage($serverId));
        }

        foreach ($cleanupServerIds as $serverId) {
            $this->messageBus->dispatch(new DeleteServerMessage($serverId));
        }

        $output->writeln(sprintf('Subscription enforcement complete. stopQueued=%d cleanupQueued=%d', count($stopServerIds), count($cleanupServerIds)));

        return Command::SUCCESS;
    }
}
