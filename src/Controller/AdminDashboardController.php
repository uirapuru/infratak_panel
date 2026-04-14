<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\ServerStatus;
use App\Repository\ServerRepository;
use App\Service\Monitoring\WorkerHealthService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class AdminDashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly WorkerHealthService $workerHealthService,
    ) {
    }

    public function index(): Response
    {
        // Non-admins land directly on their server list — the dashboard widget is admin-only
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->redirect(
                $this->adminUrlGenerator
                    ->unsetAll()
                    ->setController(AdminServerCrudController::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl()
            );
        }

        // Count servers by status
        $readyCount = $this->serverRepository->count(['status' => ServerStatus::READY->value]);
        $failedCount = $this->serverRepository->count(['status' => ServerStatus::FAILED->value]);

        // Count servers in provisioning (not READY, not FAILED, not DELETED)
        $provisioningCount = $this->serverRepository->countInProvisioning();

        $readyUrl = $this->buildStatusFilterUrl('=', [ServerStatus::READY->value]);
        $failedUrl = $this->buildStatusFilterUrl('=', [ServerStatus::FAILED->value]);
        $inProgressUrl = $this->buildStatusFilterUrl('!=', [
            ServerStatus::READY->value,
            ServerStatus::FAILED->value,
            ServerStatus::DELETED->value,
        ]);

        $workerStatuses = $this->workerHealthService->getWorkersStatus();

        return $this->render('admin/dashboard.html.twig', [
            'readyCount' => $readyCount,
            'failedCount' => $failedCount,
            'provisioningCount' => $provisioningCount,
            'readyUrl' => $readyUrl,
            'failedUrl' => $failedUrl,
            'inProgressUrl' => $inProgressUrl,
            'workerStatuses' => $workerStatuses,
        ]);
    }

    /**
     * @param list<string> $statuses
     */
    private function buildStatusFilterUrl(string $comparison, array $statuses): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(AdminServerCrudController::class)
            ->setAction(Action::INDEX)
            ->set('filters', [
                'status' => [
                    'comparison' => $comparison,
                    'value' => $statuses,
                ],
            ])
            ->generateUrl();
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Infratak Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(AdminServerCrudController::class, 'Serwery', 'fa fa-server');
        yield MenuItem::linkTo(AdminServerSubscriptionCrudController::class, 'Subscriptions', 'fa fa-credit-card')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(AdminPromoCodeCrudController::class, 'Promo codes', 'fa fa-ticket')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(AdminServerOperationLogCrudController::class, 'Operation logs', 'fa fa-list')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Administracja')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(AdminUserCrudController::class, 'Użytkownicy', 'fa fa-users')
            ->setPermission('ROLE_SUPER_ADMIN');
        yield MenuItem::section();
        yield MenuItem::linkToLogout('Wyloguj', 'fa fa-sign-out-alt');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return UserMenu::new()
            ->displayUserName(true)
            ->displayUserAvatar(false)
            ->setMenuItems([
                MenuItem::linkToLogout('Wyloguj', 'fa fa-sign-out-alt'),
            ]);
    }
}
