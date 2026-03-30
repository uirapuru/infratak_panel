<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'], priority: 1000, defaults: ['_locale' => 'pl'])]
    #[Route('/{_locale}', name: 'home_localized', methods: ['GET'], priority: 999, requirements: ['_locale' => 'pl|en'])]
    public function __invoke(): Response
    {
        return $this->render('home.html.twig');
    }
}
