<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PromoCode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminPromoCodeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PromoCode::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Promo Code')
            ->setEntityLabelInPlural('Promo Codes')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('code')
            ->setHelp('Uppercase letters, digits and hyphens. Stored as-is (auto-uppercased on save).');
        yield IntegerField::new('durationDays', 'Duration (days)')
            ->setHelp('How many days of server runtime this code grants.');
        yield IntegerField::new('maxUses', 'Max uses')
            ->setHelp('Leave empty for unlimited uses.')
            ->hideOnIndex();
        yield IntegerField::new('usedCount', 'Used')->hideOnForm();
        yield BooleanField::new('isActive', 'Active');
        yield DateTimeField::new('expiresAt', 'Expires at')
            ->setTimezone('Europe/Warsaw')
            ->setHelp('Leave empty — code never expires.')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm()->setTimezone('Europe/Warsaw');
    }
}
