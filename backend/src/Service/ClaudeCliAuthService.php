<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

final class ClaudeCliAuthService
{
    /**
     * @return array{loggedIn: bool, authMethod: string|null, apiProvider: string|null, raw: array<string, mixed>|null, error: string|null}
     */
    public function getStatus(): array
    {
        $process = new Process(
            ['sh', '-lc', 'HOME=/claude-home claude auth status'],
            '/var/www/backend',
            timeout: 10,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'loggedIn' => false,
                'authMethod' => null,
                'apiProvider' => null,
                'raw' => null,
                'error' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Claude CLI auth status failed.',
            ];
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            $output = trim($process->getOutput());
            $startJson = strpos($output, '{');
            $endJson = strrpos($output, '}');

            if ($startJson !== false && $endJson !== false && $endJson > $startJson) {
                $decoded = json_decode(substr($output, $startJson, $endJson - $startJson + 1), true);
            }
        }

        if (!is_array($decoded)) {
            return [
                'loggedIn' => false,
                'authMethod' => null,
                'apiProvider' => null,
                'raw' => null,
                'error' => 'Claude CLI auth status returned invalid JSON.',
            ];
        }

        return [
            'loggedIn' => (bool) ($decoded['loggedIn'] ?? false),
            'authMethod' => isset($decoded['authMethod']) ? (string) $decoded['authMethod'] : null,
            'apiProvider' => isset($decoded['apiProvider']) ? (string) $decoded['apiProvider'] : null,
            'raw' => $decoded,
            'error' => null,
        ];
    }
}
