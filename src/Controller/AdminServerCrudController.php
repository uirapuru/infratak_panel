<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Server;
use App\Message\CreateServerMessage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminServerCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
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
            TextField::new('domain'),
            TextField::new('portalDomain'),
            TextField::new('status'),
            TextField::new('step'),
            TextField::new('awsInstanceId'),
            TextField::new('publicIp'),
            TextField::new('lastError')->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
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
