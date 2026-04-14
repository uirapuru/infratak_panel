<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ServerSubscription;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

#[IsGranted('ROLE_ADMIN')]
final class AdminServerSubscriptionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServerSubscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Subscription')
            ->setEntityLabelInPlural('Subscriptions')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield AssociationField::new('server');
        yield AssociationField::new('user');
        yield IntegerField::new('days');
        yield MoneyField::new('amountGrossCents', 'Amount')
            ->setCurrency('PLN')
            ->setStoredAsCents();
        yield TextField::new('currency')->onlyOnDetail();
        yield DateTimeField::new('startsAt')->setTimezone('Europe/Warsaw');
        yield DateTimeField::new('expiresAt')->setTimezone('Europe/Warsaw');
        yield DateTimeField::new('createdAt')->setTimezone('Europe/Warsaw');
    }
}
