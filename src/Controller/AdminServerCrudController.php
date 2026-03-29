<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Server;
use App\Message\CreateServerMessage;
use App\Service\Server\ServerCreationService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminServerCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ServerCreationService $serverCreationService,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Server::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $retryProvisioning = Action::new('retryProvisioning', 'Retry provisioning')
            ->linkToCrudAction('retryProvisioning');

        return $actions
            ->add('index', $retryProvisioning)
            ->add('detail', $retryProvisioning);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name'),
            TextField::new('domain')->hideOnForm(),
            TextField::new('portalDomain')->hideOnForm(),
            TextField::new('status')
                ->formatValue(static fn ($value): string => $value?->value ?? '')
                ->hideOnForm(),
            TextField::new('step')
                ->formatValue(static fn ($value): string => $value?->value ?? '')
                ->hideOnForm(),
            TextField::new('awsInstanceId')->hideOnForm(),
            TextField::new('publicIp')->hideOnForm(),
            TextField::new('lastError')->hideOnIndex()->hideOnForm(),
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
        $this->addFlash('success', 'Server created and provisioning queued.');
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
