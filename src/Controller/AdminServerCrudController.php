<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Server;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\CreateServerMessage;
use App\Message\DiagnoseServerMessage;
use App\Message\ManualStopServerMessage;
use App\Message\RotateAdminPasswordMessage;
use App\Message\StartServerMessage;
use App\Service\Security\OtsAdminPasswordGenerator;
use App\Service\Server\ServerCreationService;
use App\Service\Server\ServerDeletionService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AdminServerCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly MessageBusInterface $messageBus,
        private readonly ServerCreationService $serverCreationService,
        private readonly ServerDeletionService $serverDeletionService,
        private readonly OtsAdminPasswordGenerator $passwordGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Server::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        
        // Exclude DELETED by default (users can override via status filter)
        $qb->andWhere('entity.status != :deletedStatus')
           ->setParameter('deletedStatus', ServerStatus::DELETED->value);

        return $qb;
    }

    public function configureFilters(Filters $filters): Filters
    {
        $statusChoices = array_combine(
            array_map(static fn ($case) => $case->value, ServerStatus::cases()),
            array_map(static fn ($case) => $case->value, ServerStatus::cases()),
        );

        return $filters
            ->add(TextFilter::new('id'))
            ->add(TextFilter::new('name'))
            ->add(ChoiceFilter::new('status')
                ->setChoices($statusChoices)
                ->canSelectMultiple());
    }

    public function configureActions(Actions $actions): Actions
    {
        $retryProvisioning = Action::new('retryProvisioning', 'Retry provisioning')
            ->linkToRoute('admin_admin_server_retry_provisioning', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $diagnoseProvisioning = Action::new('diagnoseProvisioning', 'Diagnose')
            ->linkToRoute('admin_admin_server_diagnose_provisioning', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $stopServer = Action::new('stopServer', 'Stop')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() === ServerStatus::READY)
            ->linkToRoute('admin_admin_server_stop', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $startServer = Action::new('startServer', 'Start')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() === ServerStatus::STOPPED)
            ->linkToRoute('admin_admin_server_start', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $resetAdminPassword = Action::new('resetAdminPassword', 'Reset admin password')
            ->displayIf(static fn (Server $server): bool => in_array($server->getStatus(), [ServerStatus::READY, ServerStatus::STOPPED], true))
            ->linkToRoute('admin_admin_server_reset_admin_password', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        return $actions
            ->disable(Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add('index', $retryProvisioning)
            ->add(Crud::PAGE_INDEX, $diagnoseProvisioning)
            ->add(Crud::PAGE_INDEX, $stopServer)
            ->add(Crud::PAGE_INDEX, $startServer)
            ->add(Crud::PAGE_INDEX, $resetAdminPassword)
            ->add(Crud::PAGE_DETAIL, $retryProvisioning)
            ->add(Crud::PAGE_DETAIL, $diagnoseProvisioning)
            ->add(Crud::PAGE_DETAIL, $stopServer)
            ->add(Crud::PAGE_DETAIL, $startServer)
            ->add(Crud::PAGE_DETAIL, $resetAdminPassword);
    }

    public function detail(AdminContext $context): Response
    {
        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof Server) {
            $passwordToReveal = $entity->getOtsAdminPasswordPendingReveal();
            if ($passwordToReveal !== null && $passwordToReveal !== '') {
                $this->addFlash('warning', sprintf('OpenTAK admin password (shown once): %s', $passwordToReveal));
                $entity->setOtsAdminPasswordPendingReveal(null);
                $this->entityManager->flush();
            }
        }

        return parent::detail($context);
    }

    public function configureFields(string $pageName): iterable
    {
        $statusChoices = array_combine(
            array_map(static fn ($case) => $case->value, ServerStatus::cases()),
            ServerStatus::cases(),
        );
        $stepChoices = array_combine(
            array_map(static fn ($case) => $case->value, ServerStep::cases()),
            ServerStep::cases(),
        );

        return [
            IdField::new('id')
                ->hideOnForm(),
            TextField::new('name'),
            TextField::new('domain')->hideOnForm(),
            ChoiceField::new('status')
                ->setChoices($statusChoices)
                ->hideOnForm(),
            ChoiceField::new('step')
                ->setChoices($stepChoices)
                ->hideOnForm(),
            TextField::new('awsInstanceId')->hideOnForm(),
            TextField::new('publicIp')->hideOnForm(),
            TextField::new('portalDomain')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('sleepAt', 'Sleep At')
                ->setRequired(false)
                ->setTimezone('Europe/Warsaw'),
            TextField::new('lastError')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('startedAt')->hideOnForm()->setTimezone('Europe/Warsaw'),
            DateTimeField::new('endedAt')->hideOnForm()->setTimezone('Europe/Warsaw'),
            DateTimeField::new('lastRetryAt')->hideOnForm()->setTimezone('Europe/Warsaw'),
            TextField::new('lastDiagnoseStatus')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('lastDiagnosedAt')->hideOnForm()->hideOnIndex()->setTimezone('Europe/Warsaw'),
            TextareaField::new('lastDiagnoseLog')->hideOnForm()->hideOnIndex(),
            NumberField::new('runtimeHours', 'Runtime')
                ->formatValue(static function ($value): string {
                    if ($value === null) {
                        return 'Null';
                    }

                    $totalMinutes = (int) round(((float) $value) * 60);
                    $days = intdiv($totalMinutes, 1440);
                    $remainingMinutes = $totalMinutes % 1440;
                    $hours = intdiv($remainingMinutes, 60);
                    $minutes = $remainingMinutes % 60;

                    return sprintf('%02d days %02d:%02d hours', $days, $hours, $minutes);
                })
                ->hideOnForm(),
            DateTimeField::new('createdAt')->hideOnForm()->setTimezone('Europe/Warsaw'),
            DateTimeField::new('updatedAt')->hideOnForm()->setTimezone('Europe/Warsaw'),
            CollectionField::new('operationLogs')->onlyOnDetail(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Server) {
            parent::persistEntity($entityManager, $entityInstance);

            return;
        }

        $this->serverCreationService->queueProvisioningForExisting($entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Server) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }

        $this->serverDeletionService->queueCleanup($entityInstance);
        $this->addFlash('success', 'Server cleanup queued.');
    }

    #[Route('/admin/admin-server/{entityId}/retry-provisioning', name: 'admin_admin_server_retry_provisioning', methods: ['GET'])]
    public function retryProvisioning(string $entityId): RedirectResponse
    {
        $server = $this->entityManager->getRepository(Server::class)->find($entityId);

        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl()
            );
        }

        $server->setLastRetryAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->messageBus->dispatch(new CreateServerMessage($server->getId()));
        $this->addFlash('success', 'Provisioning retry queued.');

        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($server->getId())
                ->generateUrl()
        );
    }

    #[Route('/admin/admin-server/{entityId}/diagnose-provisioning', name: 'admin_admin_server_diagnose_provisioning', methods: ['GET'])]
    public function diagnoseProvisioning(string $entityId): RedirectResponse
    {
        $server = $this->entityManager->getRepository(Server::class)->find($entityId);
        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl()
            );
        }

        $server->setStatus(ServerStatus::DIAGNOSING);
        $server->setStep(ServerStep::WAIT_SSM);
        $server->setLastDiagnoseStatus('running');
        $this->entityManager->flush();

        $this->messageBus->dispatch(new DiagnoseServerMessage($server->getId()));
        $this->addFlash('success', 'Diagnose queued. Refresh this page in a moment to see results.');

        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($server->getId())
                ->generateUrl()
        );
    }

    #[Route('/admin/admin-server/{entityId}/stop', name: 'admin_admin_server_stop', methods: ['GET'])]
    public function stopServer(string $entityId): RedirectResponse
    {
        $server = $this->entityManager->getRepository(Server::class)->find($entityId);
        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl()
            );
        }

        if ($server->getStatus() !== ServerStatus::READY) {
            $this->addFlash('warning', 'Stop is available only for running servers.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::DETAIL)
                    ->setEntityId($server->getId())
                    ->generateUrl()
            );
        }

        $this->messageBus->dispatch(new ManualStopServerMessage($server->getId()));
        $this->addFlash('success', 'Stop queued. Refresh this page in a moment to see updates.');

        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($server->getId())
                ->generateUrl()
        );
    }

    #[Route('/admin/admin-server/{entityId}/start', name: 'admin_admin_server_start', methods: ['GET'])]
    public function startServer(string $entityId): RedirectResponse
    {
        $server = $this->entityManager->getRepository(Server::class)->find($entityId);
        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl()
            );
        }

        if ($server->getStatus() !== ServerStatus::STOPPED) {
            $this->addFlash('warning', 'Start is available only for stopped servers.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::DETAIL)
                    ->setEntityId($server->getId())
                    ->generateUrl()
            );
        }

        $this->messageBus->dispatch(new StartServerMessage($server->getId()));
        $this->addFlash('success', 'Start queued. Refresh this page in a moment to see updates.');

        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($server->getId())
                ->generateUrl()
        );
    }

    #[Route('/admin/admin-server/{entityId}/reset-admin-password', name: 'admin_admin_server_reset_admin_password', methods: ['GET'])]
    public function resetAdminPassword(string $entityId): RedirectResponse
    {
        $server = $this->entityManager->getRepository(Server::class)->find($entityId);
        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl()
            );
        }

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null || $instanceId === '') {
            $this->addFlash('warning', 'Admin password reset requires an EC2 instance id.');

            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(self::class)
                    ->setAction(Action::DETAIL)
                    ->setEntityId($server->getId())
                    ->generateUrl()
            );
        }

        $oldPassword = $server->getOtsAdminPasswordCurrent() ?? 'password';
        $newPassword = $this->passwordGenerator->generate();

        $this->messageBus->dispatch(new RotateAdminPasswordMessage(
            serverId: $server->getId(),
            oldPassword: $oldPassword,
            newPassword: $newPassword,
            origin: 'manual-reset',
        ));

        $this->addFlash('success', 'Admin password reset queued. Open details again in a moment to see one-time password.');

        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($server->getId())
                ->generateUrl()
        );
    }
}
