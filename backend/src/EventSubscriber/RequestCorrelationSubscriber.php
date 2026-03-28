<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\RequestCorrelationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestCorrelationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RequestCorrelationService $correlation) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->correlation->ensureRequestRef($event->getRequest());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set(
            RequestCorrelationService::HEADER,
            $this->correlation->ensureRequestRef($event->getRequest()),
        );
    }
}
