<?php

declare(strict_types=1);

namespace App\Port;

use App\Enum\ConnectorType;
use App\ValueObject\AgentConfig;
use App\ValueObject\AgentResponse;
use App\ValueObject\Prompt;

interface AgentPort
{
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse;
    public function healthCheck(): bool;
    public function supportsConnector(ConnectorType $type): bool;
}
