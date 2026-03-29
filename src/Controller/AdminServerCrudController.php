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
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminServerCrudController extends AbstractCrudController
{
    public function __construct(
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

    public function configureActions(Actions $actions): Actions
    {
        $retryProvisioning = Action::new('retryProvisioning', 'Retry provisioning')
            ->linkToCrudAction('retryProvisioning');

        return $actions
            ->disable(Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add('index', $retryProvisioning)
            ->add('detail', $retryProvisioning);
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
            TextField::new('portalDomain')->hideOnForm(),
            ChoiceField::new('status')
                ->setChoices($statusChoices)
                ->hideOnForm(),
            ChoiceField::new('step')
                ->setChoices($stepChoices)
                ->hideOnForm(),
            TextField::new('awsInstanceId')->hideOnForm(),
            TextField::new('publicIp')->hideOnForm(),
            TextField::new('lastError')->hideOnForm(),
            DateTimeField::new('startedAt')->hideOnForm(),
            DateTimeField::new('endedAt')->hideOnForm(),
            NumberField::new('runtimeHours', 'Runtime [h]')
                ->setNumDecimals(2)
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

    public function retryProvisioning(AdminContext $context): RedirectResponse
    {
        $server = $context->getEntity()->getInstance();
        if ($server instanceof Server) {
            $this->messageBus->dispatch(new CreateServerMessage($server->getId()));
            $this->addFlash('success', 'Provisioning retry queued.');
        }

        return $this->redirect($context->getReferrer() ?? $this->generateUrl('admin_admin_server_index'));
    }
}
