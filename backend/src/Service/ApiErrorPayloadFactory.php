<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiErrorPayloadFactory
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    /**
     * Builds a stable API error payload with translated text plus its canonical translation identity.
     *
     * @param array<string, scalar|null> $parameters
     * @return array{
     *   error: string,
     *   i18n: array{
     *     domain: string,
     *     key: string,
     *     parameters: array<string, scalar|null>
     *   }
     * }
     */
    public function create(string $key, array $parameters = [], string $domain = 'app'): array
    {
        return [
            'error' => $this->translator->trans($key, $parameters, $domain),
            'i18n' => [
                'domain' => $domain,
                'key' => $key,
                'parameters' => $parameters,
            ],
        ];
    }

    /**
     * Builds a stable API payload using a custom translated text field name for legacy endpoints.
     *
     * @param array<string, scalar|null> $parameters
     * @return array<string, mixed>
     */
    public function createForField(string $field, string $key, array $parameters = [], string $domain = 'app'): array
    {
        $payload = $this->create($key, $parameters, $domain);
        $translated = $payload['error'];
        unset($payload['error']);
        $payload[$field] = $translated;

        return $payload;
    }

    /**
     * Normalizes a dynamic backend error message into the same API error envelope.
     *
     * @return array{error: string, i18n: null}
     */
    public function fromMessage(string $message): array
    {
        return [
            'error' => $message,
            'i18n' => null,
        ];
    }
}
