<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class RequestCorrelationService
{
    public const ATTRIBUTE = '_request_ref';
    public const HEADER = 'X-Request-Ref';

    public function __construct(private readonly RequestStack $requestStack) {}

    public function getCurrentRequestRef(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        return $this->ensureRequestRef($request);
    }

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
