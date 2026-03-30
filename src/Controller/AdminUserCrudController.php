<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Użytkownik')
            ->setEntityLabelInPlural('Użytkownicy')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Zarządzanie użytkownikami');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->remove(Crud::PAGE_INDEX, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();

        yield TextField::new('email', 'E-mail');

        yield ChoiceField::new('roles', 'Role')
            ->setChoices([
                'Admin'       => 'ROLE_ADMIN',
                'Super Admin' => 'ROLE_SUPER_ADMIN',
            ])
            ->allowMultipleChoices()
            ->renderAsBadges([
                'ROLE_ADMIN'       => 'warning',
                'ROLE_SUPER_ADMIN' => 'danger',
            ]);

        yield BooleanField::new('active', 'Aktywny');

        yield TextField::new('password', 'Hasło')
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'Nowe hasło'],
                'second_options'  => ['label' => 'Powtórz hasło'],
                'mapped'          => false,
                'required'        => $pageName === Crud::PAGE_NEW,
            ])
            ->onlyOnForms();

        yield DateTimeField::new('createdAt', 'Utworzony')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Zaktualizowany')->onlyOnDetail();
    }

    /**
     * Hash the plain-text password before persist/update.
     */
    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->hashPasswordIfProvided($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->hashPasswordIfProvided($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPasswordIfProvided(mixed $entity): void
    {
        if (!$entity instanceof User) {
            return;
        }

        /** @var string|null $plain */
        $plain = $this->getContext()?->getRequest()->request->all()['User']['password']['first'] ?? null;

        if ($plain !== null && $plain !== '') {
            $entity->setPassword($this->passwordHasher->hashPassword($entity, $plain));
        }
    }
}
