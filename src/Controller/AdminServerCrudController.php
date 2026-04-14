<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Server;
use App\Entity\User;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\CreateServerMessage;
use App\Repository\ServerOperationLogRepository;
use App\Repository\ServerRepository;
use App\Message\DiagnoseServerMessage;
use App\Message\ManualStopServerMessage;
use App\Message\RotateAdminPasswordMessage;
use App\Message\StartServerMessage;
use App\Service\Billing\SubscriptionService;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Server::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultRowAction(Action::DETAIL);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        // Exclude DELETED by default (admins can override via status filter)
        $qb->andWhere('entity.status != :deletedStatus')
           ->setParameter('deletedStatus', ServerStatus::DELETED->value);

        // Non-admins see only their own servers
        if (!$this->isGranted('ROLE_ADMIN')) {
            $qb->andWhere('entity.owner = :currentUser')
               ->setParameter('currentUser', $this->getUser());
        }

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
        $resetAdminPassword = Action::new('resetAdminPassword', 'Reset admin password')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() === ServerStatus::READY)
            ->linkToRoute('admin_admin_server_reset_admin_password', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $showDetail = Action::new(Action::DETAIL, 'Pokaż szczegóły')
            ->linkToCrudAction(Action::DETAIL);

        // Non-admins: only "Pokaż szczegóły" + "Reset admin password" on index
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $actions
                ->disable(Action::EDIT, Action::DELETE, Action::NEW)
                ->add(Crud::PAGE_INDEX, $showDetail)
                ->add(Crud::PAGE_INDEX, $resetAdminPassword);
        }

        $retryProvisioning = Action::new('retryProvisioning', 'Retry provisioning')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() !== ServerStatus::STOPPED)
            ->linkToRoute('admin_admin_server_retry_provisioning', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $diagnoseProvisioning = Action::new('diagnoseProvisioning', 'Diagnose')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() !== ServerStatus::STOPPED)
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

        $changeAdminPassword = Action::new('changeAdminPassword', 'Change admin password')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() === ServerStatus::READY)
            ->linkToRoute('admin_admin_server_change_admin_password', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $addSubscription = Action::new('addSubscription', 'Add subscription')
            ->displayIf(static fn (Server $server): bool => $server->getStatus() !== ServerStatus::DELETED)
            ->linkToRoute('admin_admin_server_add_subscription', static fn (Server $server): array => [
                'entityId' => $server->getId(),
            ]);

        $showDetail = Action::new(Action::DETAIL, 'Pokaż szczegóły')
            ->linkToCrudAction(Action::DETAIL);

        return $actions
            ->disable(Action::EDIT)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action->askConfirmation())
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action) => $action->askConfirmation())
            ->add(Crud::PAGE_INDEX, $showDetail)
            ->add('index', $retryProvisioning)
            ->add(Crud::PAGE_INDEX, $diagnoseProvisioning)
            ->add(Crud::PAGE_INDEX, $stopServer)
            ->add(Crud::PAGE_INDEX, $startServer)
            ->add(Crud::PAGE_INDEX, $resetAdminPassword)
            ->add(Crud::PAGE_DETAIL, $retryProvisioning)
            ->add(Crud::PAGE_DETAIL, $diagnoseProvisioning)
            ->add(Crud::PAGE_DETAIL, $stopServer)
            ->add(Crud::PAGE_DETAIL, $startServer)
            ->add(Crud::PAGE_DETAIL, $resetAdminPassword)
            ->add(Crud::PAGE_DETAIL, $changeAdminPassword)
            ->add(Crud::PAGE_DETAIL, $addSubscription);
    }

    public function configureFields(string $pageName): iterable
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $statusChoices = array_combine(
            array_map(static fn ($case) => $case->value, ServerStatus::cases()),
            ServerStatus::cases(),
        );
        $stepChoices = array_combine(
            array_map(static fn ($case) => $case->value, ServerStep::cases()),
            ServerStep::cases(),
        );

        yield IdField::new('id')->hideOnForm();

        if ($isAdmin) {
            yield TextField::new('name');
        }

        yield TextField::new('domain')
            ->hideOnForm()
            ->setColumns(12)
            ->renderAsHtml()
            ->formatValue(static function ($value): string {
                if (!is_string($value) || $value === '') {
                    return '';
                }

                $safeHost = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return sprintf('<a href="https://%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>', $safeHost);
            });

        yield ChoiceField::new('status')
            ->setChoices($statusChoices)
            ->hideOnForm();

        if ($isAdmin) {
            yield ChoiceField::new('step')
                ->setChoices($stepChoices)
                ->hideOnForm();
            yield TextField::new('awsInstanceId')->hideOnForm();
        }

        yield TextField::new('publicIp')->hideOnForm();

        yield TextField::new('portalDomain')
            ->hideOnForm()
            ->hideOnIndex()
            ->setColumns(12)
            ->renderAsHtml()
            ->formatValue(static function ($value): string {
                if (!is_string($value) || $value === '') {
                    return '';
                }

                $safeHost = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return sprintf('<a href="https://%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>', $safeHost);
            });

        if ($isAdmin) {
            yield DateTimeField::new('sleepAt', 'Sleep At')
                ->setRequired(false)
                ->setTimezone('Europe/Warsaw');
            yield AssociationField::new('owner', 'Owner')
                ->setRequired(false);
        }

        yield DateTimeField::new('subscriptionPaidUntil', 'Subscription paid until')
            ->hideOnForm()
            ->setTimezone('Europe/Warsaw');

        if ($isAdmin) {
            yield DateTimeField::new('subscriptionExpiredAt', 'Subscription expired at')
                ->hideOnForm()
                ->hideOnIndex()
                ->setTimezone('Europe/Warsaw');
            yield DateTimeField::new('subscriptionTerminationQueuedAt', 'Subscription cleanup queued at')
                ->hideOnForm()
                ->hideOnIndex()
                ->setTimezone('Europe/Warsaw');
            yield TextField::new('lastError')->hideOnForm()->hideOnIndex();
        }

        yield DateTimeField::new('startedAt')->hideOnForm()->setTimezone('Europe/Warsaw');
        yield DateTimeField::new('endedAt')->hideOnForm()->setTimezone('Europe/Warsaw');

        if ($isAdmin) {
            yield DateTimeField::new('lastRetryAt')->hideOnForm()->setTimezone('Europe/Warsaw');
        }

        yield TextField::new('otsAdminPasswordCurrent', 'Dane logowania do OTS')
            ->onlyOnDetail()
            ->renderAsHtml()
            ->formatValue(static function ($value, Server $server): string {
                $domain = $server->getDomain();
                $loginUrl = ($domain !== null && $domain !== '')
                    ? sprintf('https://%s/login', htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                    : null;

                $copyBtn = static function (string $text): string {
                    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    return sprintf(
                        '<button type="button" class="btn btn-sm btn-outline-secondary js-copy-password" data-copy-text="%s" aria-label="Kopiuj"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>',
                        $safe,
                    );
                };

                $loginLink = $loginUrl !== null
                    ? sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info ms-1">Otwórz panel OTS</a>', $loginUrl)
                    : '';

                if (!is_string($value) || $value === '') {
                    return '<span class="text-muted">Hasło pojawi się po zakończeniu provisioningu.</span>'.$loginLink;
                }

                $safePassword = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return sprintf(
                    '<table class="table table-sm table-borderless mb-0" style="max-width:480px">
                        <tr><td class="text-muted pe-3 py-1" style="white-space:nowrap">Login</td>
                            <td class="py-1"><code>administrator</code> %s</td></tr>
                        <tr><td class="text-muted pe-3 py-1" style="white-space:nowrap">Hasło</td>
                            <td class="py-1"><code>%s</code> %s</td></tr>
                        <tr><td class="text-muted pe-3 py-1" style="white-space:nowrap">Panel OTS</td>
                            <td class="py-1">%s</td></tr>
                    </table>',
                    $copyBtn('administrator'),
                    $safePassword,
                    $copyBtn($value),
                    $loginLink !== '' ? $loginLink : '<span class="text-muted">—</span>',
                );
            });

        if ($isAdmin) {
            yield TextField::new('lastDiagnoseStatus')->hideOnForm()->hideOnIndex();
            yield DateTimeField::new('lastDiagnosedAt')->hideOnForm()->hideOnIndex()->setTimezone('Europe/Warsaw');
            yield TextareaField::new('lastDiagnoseLog')->hideOnForm()->hideOnIndex();
            yield DateTimeField::new('otsAdminPasswordRotatedAt', 'OpenTAK admin password rotated at')
                ->onlyOnDetail()
                ->setTimezone('Europe/Warsaw');
            yield NumberField::new('runtimeHours', 'Runtime')
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
                ->hideOnForm();
        }

        yield DateTimeField::new('createdAt')->hideOnForm()->setTimezone('Europe/Warsaw');
        yield DateTimeField::new('updatedAt')->hideOnForm()->setTimezone('Europe/Warsaw');

        if ($isAdmin) {
            yield CollectionField::new('operationLogs')->onlyOnDetail();
        }
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

        $this->assertCanAccessServer($entityInstance);
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

        $this->assertCanAccessServer($server);
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

        $this->assertCanAccessServer($server);
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

        $this->assertCanAccessServer($server);

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

        $this->assertCanAccessServer($server);

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

        $this->assertCanAccessServer($server);

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

        $this->addFlash('success', 'Admin password reset queued. The current password will be available in the server detail after the worker completes the rotation.');

        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($server->getId())
                ->generateUrl()
        );
    }

    #[Route('/admin/admin-server/{entityId}/change-admin-password', name: 'admin_admin_server_change_admin_password', methods: ['GET', 'POST'])]
    public function changeAdminPassword(string $entityId, Request $request): Response
    {
        $server = $this->entityManager->getRepository(Server::class)->find($entityId);
        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirectToServerIndex();
        }

        $this->assertCanAccessServer($server);

        $detailUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($server->getId())
            ->generateUrl();

        if ($server->getStatus() !== ServerStatus::READY) {
            $this->addFlash('warning', 'Admin password change is available only for ready servers.');

            return $this->redirect($detailUrl);
        }

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null || $instanceId === '') {
            $this->addFlash('warning', 'Admin password change requires an EC2 instance id.');

            return $this->redirect($detailUrl);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change_ots_admin_password_'.$server->getId(), (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'Invalid CSRF token.');

                return $this->redirect($request->getUri());
            }

            $newPassword = (string) $request->request->get('newPassword', '');
            $confirmPassword = (string) $request->request->get('confirmPassword', '');

            if (strlen($newPassword) < 16) {
                $this->addFlash('danger', 'New password must have at least 16 characters.');

                return $this->redirect($request->getUri());
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('danger', 'Password confirmation does not match.');

                return $this->redirect($request->getUri());
            }

            $oldPassword = $server->getOtsAdminPasswordCurrent() ?? 'password';
            $this->messageBus->dispatch(new RotateAdminPasswordMessage(
                serverId: $server->getId(),
                oldPassword: $oldPassword,
                newPassword: $newPassword,
                origin: 'manual-change',
            ));

            $this->addFlash('success', 'Admin password change queued. The new password will be available in the server detail after the worker completes the rotation.');

            return $this->redirect($detailUrl);
        }

        return $this->render('admin/change_ots_admin_password.html.twig', [
            'server' => $server,
            'detailUrl' => $detailUrl,
            'csrfTokenId' => 'change_ots_admin_password_'.$server->getId(),
        ]);
    }

    #[Route('/admin/admin-server/{entityId}/add-subscription', name: 'admin_admin_server_add_subscription', methods: ['GET', 'POST'])]
    public function addSubscription(string $entityId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->entityManager->getRepository(Server::class)->find($entityId);
        if (!$server instanceof Server) {
            $this->addFlash('danger', 'Server not found.');

            return $this->redirectToServerIndex();
        }

        $detailUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($server->getId())
            ->generateUrl();

        if ($server->getStatus() === ServerStatus::DELETED) {
            $this->addFlash('warning', 'Cannot add subscription to a deleted server.');

            return $this->redirect($detailUrl);
        }

        $currentUser = $this->getUser();
        $owner = $server->getOwner();
        if (!$owner instanceof User) {
            if (!$currentUser instanceof User) {
                $this->addFlash('danger', 'Server owner is missing and current admin user cannot be used as owner.');

                return $this->redirect($detailUrl);
            }

            $owner = $currentUser;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('add_subscription_'.$server->getId(), (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'Invalid CSRF token.');

                return $this->redirect($request->getUri());
            }

            $days = (int) $request->request->get('days', 0);
            if ($days < 1 || $days > 366) {
                $this->addFlash('danger', 'Subscription length must be between 1 and 366 days.');

                return $this->redirect($request->getUri());
            }

            try {
                $subscription = $this->subscriptionService->purchase($server, $owner, $days);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirect($request->getUri());
            }

            $this->addFlash('success', sprintf(
                'Subscription added for %d day(s). Amount: %s %s. Paid until: %s.',
                $subscription->getDays(),
                $subscription->getAmountGrossPln(),
                $subscription->getCurrency(),
                $subscription->getExpiresAt()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i'),
            ));

            return $this->redirect($detailUrl);
        }

        return $this->render('admin/add_subscription.html.twig', [
            'server' => $server,
            'owner' => $owner,
            'detailUrl' => $detailUrl,
            'csrfTokenId' => 'add_subscription_'.$server->getId(),
            'pricePerDayPln' => '50.00',
        ]);
    }

    #[Route('/admin/api/servers', name: 'admin_api_servers', methods: ['GET'])]
    public function apiServers(
        ServerRepository $serverRepository,
        ServerOperationLogRepository $logRepository,
    ): JsonResponse {
        $qb = $serverRepository->createQueryBuilder('s')
            ->andWhere('s.status != :deleted')
            ->setParameter('deleted', ServerStatus::DELETED->value)
            ->orderBy('s.createdAt', 'DESC');

        if (!$this->isGranted('ROLE_ADMIN')) {
            $qb->andWhere('s.owner = :currentUser')
               ->setParameter('currentUser', $this->getUser());
        }

        $servers = $qb->getQuery()->getResult();

        $data = array_map(function (Server $server) use ($logRepository): array {
            $logs = $logRepository->findRecentForServer($server->getId());

            return [
                'id' => $server->getId(),
                'name' => $server->getName(),
                'status' => $server->getStatus()->value,
                'step' => $server->getStep()->value,
                'updatedAt' => $server->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'recentLogs' => array_map(
                    static fn ($log): array => [
                        'id' => $log->getId(),
                        'level' => $log->getLevel(),
                        'message' => $log->getMessage(),
                        'status' => $log->getStatus(),
                        'step' => $log->getStep(),
                        'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    ],
                    $logs,
                ),
            ];
        }, $servers);

        return new JsonResponse($data);
    }

    #[Route('/admin/api/servers/{id}', name: 'admin_api_server_detail', methods: ['GET'])]
    public function apiServerDetail(
        string $id,
        ServerRepository $serverRepository,
        ServerOperationLogRepository $logRepository,
    ): JsonResponse {
        $server = $serverRepository->find($id);
        if ($server === null) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && $server->getOwner() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $logs = $logRepository->findAllForServer($server->getId());

        return new JsonResponse([
            'id' => $server->getId(),
            'name' => $server->getName(),
            'status' => $server->getStatus()->value,
            'step' => $server->getStep()->value,
            'updatedAt' => $server->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'logs' => array_map(
                static fn ($log): array => [
                    'id' => $log->getId(),
                    'level' => $log->getLevel(),
                    'message' => $log->getMessage(),
                    'status' => $log->getStatus(),
                    'step' => $log->getStep(),
                    'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'contextData' => $log->getContextData(),
                ],
                $logs,
            ),
        ]);
    }

    /**
     * Throws AccessDenied when a non-admin user tries to access a server they do not own.
     */
    private function assertCanAccessServer(Server $server): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        if ($server->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You do not have access to this server.');
        }
    }

    private function redirectToServerIndex(): RedirectResponse
    {
        return $this->redirect(
            $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl()
        );
    }

}
