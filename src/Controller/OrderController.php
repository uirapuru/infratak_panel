<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\PromoCodeRepository;
use App\Repository\UserRepository;
use App\Service\Billing\SubscriptionService;
use App\Service\Server\ServerCreationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    /** Available server types. */
    private const array SERVER_TYPES = [
        'opentak' => [
            'name'        => 'OpenTAK Server z Boarding Portalem',
            'description' => 'Dedykowana instancja OpenTAK Server (OTS) uruchomiona na AWS, gotowa w ciągu kilku minut. W zestawie: portal do onboardingu użytkowników, certyfikat TLS, konfiguracja DNS pod Twoją subdomenę.',
            'features'    => [
                'Pełna instancja OpenTAK Server (OTS)',
                'Boarding Portal dla uczestników',
                'Certyfikat TLS (Let\'s Encrypt)',
                'Dedykowana subdomena infratak.com',
                'Możliwość zatrzymania i wznowienia w dowolnym momencie',
            ],
            'price_label' => '50 PLN / dzień',
        ],
    ];

    /** Subdomain validation: lowercase letters, digits, hyphens; 2–32 chars; no leading/trailing hyphen. */
    private const string SUBDOMAIN_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,30}[a-z0-9]$|^[a-z0-9]$/';

    #[Route('/zamow', name: 'order_select', methods: ['GET'])]
    public function select(): Response
    {
        return $this->render('order/select.html.twig', [
            'serverTypes' => self::SERVER_TYPES,
        ]);
    }

    #[Route('/zamow/rejestracja', name: 'order_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ServerCreationService $serverCreationService,
        SubscriptionService $subscriptionService,
        PromoCodeRepository $promoCodeRepository,
    ): Response {
        $type = (string) $request->query->get('type', 'opentak');

        if (!isset(self::SERVER_TYPES[$type])) {
            return $this->redirectToRoute('order_select');
        }

        $serverType = self::SERVER_TYPES[$type];
        $errors = [];

        if ($request->isMethod('POST')) {
            $firstName = trim((string) $request->request->get('firstName', ''));
            $lastName  = trim((string) $request->request->get('lastName', ''));
            $phone     = trim((string) $request->request->get('phone', ''));
            $email     = strtolower(trim((string) $request->request->get('email', '')));
            $password  = (string) $request->request->get('password', '');
            $subdomain = strtolower(trim((string) $request->request->get('subdomain', '')));
            $promoCodeInput = strtoupper(trim((string) $request->request->get('promoCode', '')));

            // Check whether the account already exists (affects password validation)
            $existingUser = filter_var($email, FILTER_VALIDATE_EMAIL)
                ? $userRepository->findOneBy(['email' => $email])
                : null;

            // Validate fields
            if ($firstName === '') {
                $errors['firstName'] = 'Imię jest wymagane.';
            }
            if ($lastName === '') {
                $errors['lastName'] = 'Nazwisko jest wymagane.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Podaj poprawny adres e-mail.';
            }
            // Password only required when creating a new account
            if ($existingUser === null && strlen($password) < 8) {
                $errors['password'] = 'Hasło musi mieć co najmniej 8 znaków.';
            }
            if ($subdomain === '' || !preg_match(self::SUBDOMAIN_PATTERN, $subdomain)) {
                $errors['subdomain'] = 'Subdomena może zawierać tylko litery a–z, cyfry i myślniki, musi mieć 2–32 znaki i nie może zaczynać ani kończyć się myślnikiem.';
            }

            // Validate promo code
            $promoCode = null;
            if ($promoCodeInput === '') {
                $errors['promoCode'] = 'Rejestracja wymaga kodu promocyjnego.';
            } else {
                $promoCode = $promoCodeRepository->findValidByCode($promoCodeInput);
                if ($promoCode === null || !$promoCode->isValid()) {
                    $errors['promoCode'] = 'Nieprawidłowy lub wygasły kod promocyjny.';
                    $promoCode = null;
                }
            }

            if ($errors === [] && $promoCode !== null) {
                try {
                    if ($existingUser !== null) {
                        $user = $existingUser;
                    } else {
                        $user = new User($email, ['ROLE_USER']);
                        $user->setActive(true);
                        $user->setPassword($passwordHasher->hashPassword($user, $password));
                        $user->setFirstName($firstName);
                        $user->setLastName($lastName);
                        $user->setPhone($phone !== '' ? $phone : null);
                        $entityManager->persist($user);
                    }

                    $entityManager->flush();

                    $server = $serverCreationService->createFromName($subdomain, owner: $user);

                    // Apply promo code — grant subscription for the code's duration
                    $promoCode->incrementUsedCount();
                    $entityManager->flush();

                    $subscriptionService->purchase($server, $user, $promoCode->getDurationDays());

                    $request->getSession()->set('order_success', [
                        'email'        => $email,
                        'password'     => $password,
                        'serverName'   => $server->getName(),
                        'domain'       => $server->getDomain(),
                        'portalDomain' => $server->getPortalDomain(),
                        'durationDays' => $promoCode->getDurationDays(),
                    ]);

                    return $this->redirectToRoute('order_success');
                } catch (\InvalidArgumentException $e) {
                    $errors['subdomain'] = 'Ta nazwa serwera jest już zajęta. Wybierz inną.';
                } catch (\Throwable) {
                    $errors['_general'] = 'Wystąpił błąd podczas tworzenia konta. Spróbuj ponownie.';
                }
            }

            return $this->render('order/register.html.twig', [
                'serverType' => $serverType,
                'type'       => $type,
                'errors'     => $errors,
                'values'     => compact('firstName', 'lastName', 'phone', 'email', 'subdomain', 'promoCodeInput'),
            ]);
        }

        return $this->render('order/register.html.twig', [
            'serverType' => $serverType,
            'type'       => $type,
            'errors'     => [],
            'values'     => ['firstName' => '', 'lastName' => '', 'phone' => '', 'email' => '', 'subdomain' => '', 'promoCodeInput' => ''],
        ]);
    }

    #[Route('/zamow/sukces', name: 'order_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $data = $request->getSession()->get('order_success');

        if (!is_array($data)) {
            return $this->redirectToRoute('order_select');
        }

        $request->getSession()->remove('order_success');

        return $this->render('order/success.html.twig', [
            'email'        => $data['email'],
            'password'     => $data['password'],
            'serverName'   => $data['serverName'],
            'domain'       => $data['domain'],
            'portalDomain' => $data['portalDomain'],
            'durationDays' => $data['durationDays'],
        ]);
    }
}
