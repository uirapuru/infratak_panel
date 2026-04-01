<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Psr\Log\LoggerInterface;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('home');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This route is intercepted by the firewall logout handler.');
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): Response {
        if ($request->isMethod('POST')) {
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $plainPassword = (string) $request->request->get('password', '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Podaj poprawny adres e-mail.');

                return $this->redirectToRoute('app_register');
            }

            if (strlen($plainPassword) < 8) {
                $this->addFlash('error', 'Hasło musi mieć co najmniej 8 znaków.');

                return $this->redirectToRoute('app_register');
            }

            if ($userRepository->findOneBy(['email' => $email]) !== null) {
                $this->addFlash('error', 'Konto z tym adresem e-mail już istnieje.');

                return $this->redirectToRoute('app_register');
            }

            $user = new User($email, ['ROLE_USER']);
            $user->setActive(false);
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            $tokenPlain = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $tokenPlain);
            $token = new EmailVerificationToken(
                $user,
                $tokenHash,
                new \DateTimeImmutable('+24 hours'),
            );

            $entityManager->persist($user);
            $entityManager->persist($token);
            $entityManager->flush();

            $verifyUrl = $this->generateUrl('app_verify_email', [
                'token' => $tokenPlain,
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $emailMessage = (new Email())
                ->from($_ENV['MAILER_FROM'] ?? 'no-reply@infratak.local')
                ->to($email)
                ->subject('Potwierdź konto Infratak')
                ->html(sprintf(
                    '<p>Dziękujemy za rejestrację.</p><p>Kliknij, aby potwierdzić konto: <a href="%s">%s</a></p>',
                    htmlspecialchars($verifyUrl, ENT_QUOTES),
                    htmlspecialchars($verifyUrl, ENT_QUOTES),
                ));

            try {
                $mailer->send($emailMessage);
            } catch (TransportExceptionInterface $exception) {
                // Registration must not end with 500 when mail transport is down.
                // Remove the just-created inactive user and token to allow clean retry.
                $entityManager->remove($token);
                $entityManager->remove($user);
                $entityManager->flush();

                $logger->error('Registration verification e-mail delivery failed.', [
                    'email' => $email,
                    'exception' => $exception,
                ]);

                $this->addFlash('error', 'Nie udalo sie wyslac e-maila weryfikacyjnego. Sprobuj ponownie za chwile.');

                return $this->redirectToRoute('app_register');
            }

            $this->addFlash('success', 'Konto utworzone. Sprawdź skrzynkę e-mail i potwierdź konto.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig');
    }

    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(
        Request $request,
        EmailVerificationTokenRepository $tokenRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tokenPlain = (string) $request->query->get('token', '');
        if ($tokenPlain === '') {
            $this->addFlash('error', 'Brak tokenu weryfikacyjnego.');

            return $this->redirectToRoute('app_login');
        }

        $tokenHash = hash('sha256', $tokenPlain);
        $token = $tokenRepository->findUsableByHash($tokenHash);

        if ($token === null) {
            $this->addFlash('error', 'Token jest niepoprawny lub wygasł.');

            return $this->redirectToRoute('app_login');
        }

        $user = $token->getUser();
        $user->setActive(true);
        $token->markUsed();
        $entityManager->flush();

        $this->addFlash('success', 'Konto zostało potwierdzone. Możesz się zalogować.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/admin/login', name: 'app_admin_login')]
    public function adminLogin(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('admin');
            }

            return $this->redirectToRoute('home');
        }

        return $this->render('admin/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/admin/logout', name: 'app_admin_logout')]
    public function adminLogout(): never
    {
        throw new \LogicException('This route is intercepted by the firewall logout handler.');
    }
}
