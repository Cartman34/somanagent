<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Generates and retrieves per-request correlation IDs for distributed tracing.
 */
final class RequestCorrelationService
{
    public const ATTRIBUTE = '_request_ref';
    public const HEADER = 'X-Request-Ref';

    /**
     * Initializes the service with the current request stack.
     */
    public function __construct(private readonly RequestStack $requestStack) {}

    /**
     * Returns the current request correlation identifier when a request is active.
     */
    public function getCurrentRequestRef(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        return $this->ensureRequestRef($request);
    }

    /**
     * Reuses an incoming request reference or generates a new one and stores it on the request.
     */
    public function ensureRequestRef(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE)
            ?? $request->headers->get(self::HEADER);

        if (is_string($existing) && $existing !== '') {
            $request->attributes->set(self::ATTRIBUTE, $existing);
            return $existing;
        }

        $requestRef = Uuid::v7()->toRfc4122();
        $request->attributes->set(self::ATTRIBUTE, $requestRef);

        return $requestRef;
    }
}
