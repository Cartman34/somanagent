<?php

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Enum\ConnectorType;
use App\Port\AgentPort;
use App\ValueObject\AgentConfig;
use App\ValueObject\AgentResponse;
use App\ValueObject\Prompt;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ClaudeCliAdapter implements AgentPort
{
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse
    {
        $start = microtime(true);

        $process = new Process(
            command: ['claude', '--print', '--model', $config->model, $prompt->build()],
            timeout: $config->timeout,
        );

        $process->run();

        $durationMs = (microtime(true) - $start) * 1000;

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return AgentResponse::fromCli($process->getOutput(), $durationMs);
    }

    public function healthCheck(): bool
    {
        $process = new Process(['claude', '--version'], timeout: 5);
        $process->run();
        return $process->isSuccessful();
    }

    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::ClaudeCli;
    }
}
