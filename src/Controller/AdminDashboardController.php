<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Server;
use App\Entity\ServerOperationLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class AdminDashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirect($this->generateUrl('admin_admin_server_index'));
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Infratak Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkTo(AdminServerCrudController::class, 'Servers', 'fa fa-server');
        yield MenuItem::linkTo(AdminServerOperationLogCrudController::class, 'Operation logs', 'fa fa-list');
    }
}
