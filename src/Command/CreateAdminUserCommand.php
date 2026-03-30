<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:create-user',
    description: 'Create an admin panel user (ROLE_ADMIN or ROLE_SUPER_ADMIN)',
)]
final class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email',    InputArgument::REQUIRED, 'User email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain-text password (will be hashed)')
            ->addOption('super-admin', null, InputOption::VALUE_NONE, 'Grant ROLE_SUPER_ADMIN instead of ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email    = $input->getArgument('email');
        $plain    = $input->getArgument('password');
        $isSuperAdmin = $input->getOption('super-admin');

        $roles = $isSuperAdmin ? ['ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN'];

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            $io->error(sprintf('User "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User($email, $roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plain));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'User "%s" created with role %s.',
            $email,
            implode(', ', $roles),
        ));

        return Command::SUCCESS;
    }
}
