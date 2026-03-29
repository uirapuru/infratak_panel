<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ServerOperationLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class AdminServerOperationLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServerOperationLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('server')->setCrudController(AdminServerCrudController::class),
            TextField::new('level'),
            TextField::new('status'),
            TextField::new('step'),
            TextField::new('message'),
            ArrayField::new('contextData')->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm(),
        ];
    }
}
