<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Server;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\CreateServerMessage;
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
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

        return $actions
            ->disable(Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add('index', $retryProvisioning)
            ->add(Crud::PAGE_DETAIL, $retryProvisioning);
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
            TextField::new('lastError')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('startedAt')->hideOnForm(),
            DateTimeField::new('endedAt')->hideOnForm(),
            DateTimeField::new('lastRetryAt')->hideOnForm(),
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
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
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
}
