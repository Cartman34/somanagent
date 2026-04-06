<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\RequestCorrelationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches a unique correlation ID to each request for cross-service log tracing.
 */
final class RequestCorrelationSubscriber implements EventSubscriberInterface
{
    /**
     * Initializes the subscriber with the request-correlation service.
     */
    public function __construct(private readonly RequestCorrelationService $correlation) {}

    /**
     * Declares the kernel events handled by this subscriber.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    /**
     * Ensures the current main request has a correlation identifier.
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->correlation->ensureRequestRef($event->getRequest());
    }

    /**
     * Adds the request correlation identifier to the main response headers.
     */
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
