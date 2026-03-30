<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony-level fallback guard enforcing APP_ROLE path restrictions.
 * Primary enforcement must happen at nginx level (landing.conf / admin.conf).
 */
final class RouteGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $appRole,
        private readonly string $appEnv,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // In dev the nginx role-split does not apply — both roles share one container.
        if ($this->appEnv === 'dev') {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if ($this->appRole === 'landing' && str_starts_with($path, '/admin')) {
            throw new AccessDeniedHttpException('Admin panel is not available on this endpoint.');
        }

        if ($this->appRole === 'admin' && !str_starts_with($path, '/admin') && $path !== '/health') {
            throw new AccessDeniedHttpException('Only admin routes are available on this endpoint.');
        }
    }
}
