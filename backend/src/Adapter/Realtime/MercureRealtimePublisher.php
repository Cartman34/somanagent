<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\Realtime;

use App\Port\RealtimePublisherPort;
use App\ValueObject\RealtimeUpdate;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes realtime updates to a Mercure hub.
 */
final class MercureRealtimePublisher implements RealtimePublisherPort
{
    private const JWT_ALGORITHM = 'HS256';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $hubUrl,
        private readonly string $jwtSecret,
    ) {}

    public function publish(RealtimeUpdate $update): void
    {
        $payload = [];
        foreach ($update->getTopics() as $topic) {
            $payload[] = 'topic=' . rawurlencode($topic);
        }

        $payload[] = 'id=' . rawurlencode($update->getId());
        $payload[] = 'type=' . rawurlencode($update->getType());
        $payload[] = 'data=' . rawurlencode((string) json_encode($update->toEnvelope(), JSON_THROW_ON_ERROR));

        if ($update->isPrivate()) {
            $payload[] = 'private=on';
        }

        try {
            $response = $this->httpClient->request('POST', $this->hubUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->createPublisherJwt(),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => implode('&', $payload),
            ]);

            $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Unable to publish Mercure update: ' . $exception->getMessage(), previous: $exception);
        }
    }

    private function createPublisherJwt(): string
    {
        $header = $this->encodeJwtSegment([
            'alg' => self::JWT_ALGORITHM,
            'typ' => 'JWT',
        ]);
        $payload = $this->encodeJwtSegment([
            'mercure' => [
                'publish' => ['*'],
            ],
        ]);

        $signature = hash_hmac('sha256', $header . '.' . $payload, $this->jwtSecret, true);

        return sprintf('%s.%s.%s', $header, $payload, $this->base64UrlEncode($signature));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJwtSegment(array $data): string
    {
        return $this->base64UrlEncode((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
