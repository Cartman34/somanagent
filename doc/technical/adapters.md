# Ports and Adapters

> See also: [Architecture](architecture.md) · [Configuration](configuration.md) · [AI Agents](../functional/agents.md)

## Principle

Adapters allow **replacing an implementation** without modifying the business logic. Each port is a PHP interface; adapters are the concrete implementations injected by Symfony.

## AgentPort — Communication with AI

**Interface**: `src/Port/AgentPort.php`

```php
interface AgentPort
{
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse;
    public function healthCheck(): bool;
    public function supportsConnector(ConnectorType $type): bool;
}
```

### Adapter Selection

`AgentPortRegistry` (Symfony tagged services) resolves the correct adapter based on the agent's `ConnectorType`:

```yaml
# services.yaml
App\Adapter\AI\ClaudeApiAdapter:
    arguments: { $apiKey: '%env(CLAUDE_API_KEY)%' }
    tags: ['app.agent_adapter']

App\Adapter\AI\ClaudeCliAdapter:
    tags: ['app.agent_adapter']
```

When executing a step:
```php
$adapter = $this->registry->getFor($agent->getConnector()); // ConnectorType::ClaudeApi
$response = $adapter->sendPrompt($prompt, $agent->getAgentConfig());
```

### ClaudeApiAdapter (`claude_api`)

- Sends an HTTP POST request to `https://api.anthropic.com/v1/messages`
- Requires `CLAUDE_API_KEY` in `.env`
- Returns an `AgentResponse` with consumed tokens and duration

### ClaudeCliAdapter (`claude_cli`)

- Executes `claude --print --model <model> <prompt>` via `Symfony\Component\Process`
- Requires the `claude` binary to be in the host machine's PATH
- Configurable timeout via `AgentConfig.timeout`

### Adding a New AI Adapter

1. Create `src/Adapter/AI/MyAiAdapter.php` implementing `AgentPort`
2. Add the value to the `ConnectorType` enum
3. Tag it in `services.yaml` with `app.agent_adapter`
4. `AgentPortRegistry` will resolve it automatically

---

## VCSPort — Git Integration

**Interface**: `src/Port/VCSPort.php`

```php
interface VCSPort
{
    public function getRepository(string $owner, string $repo): array;
    public function createBranch(string $owner, string $repo, string $branch, string $from): void;
    public function openPullRequest(...): array;
    public function getDiff(string $owner, string $repo, string $pullRequestId): string;
    public function healthCheck(): bool;
    public function getProviderName(): string;
}
```

### GitHubAdapter

- Authenticated via `GITHUB_TOKEN`
- Base URL: `https://api.github.com`
- Uses the GitHub v3 API (header `Accept: application/vnd.github.v3+json`)

### GitLabAdapter

- Authenticated via `GITLAB_TOKEN`
- Configurable base URL (for self-hosted instances) via `GITLAB_URL`
- Uses the GitLab v4 API

### Usage

VCS adapters are not injected via a registry: the adapter to use is determined by the module configuration (coming soon). For now, it can be injected directly into services.

---

## SkillPort — Skill Import

**Interface**: `src/Port/SkillPort.php`

```php
interface SkillPort
{
    public function import(string $ownerAndName): array; // returns SKILL.md metadata
    public function search(string $query = ''): array;
}
```

### SkillsShAdapter

- Runs `npx skills add <owner/name>` in `skills/imported/`
- Parses the YAML frontmatter + Markdown body of the resulting `SKILL.md`
- Returns `['slug', 'name', 'description', 'content', 'filePath', 'originalSource']`

**Prerequisites**: Node.js and npm must be available (in the `node` container).

---

## Prompt Construction

The `ValueObject\Prompt` assembles the final text sent to the agent:

```
# Skill instructions

[SKILL.md content]

---

# Context

**module**: api-php
**pr_diff**: [code diff]

---

# Task

[specific instruction from the workflow step]
```

`Prompt::build()` returns this text. It is passed to `AgentPort::sendPrompt()`.

---

## AgentResponse

Immutable object returned by adapters:

| Property | Description |
|---|---|
| `content` | AI response text |
| `inputTokens` | Tokens consumed as input |
| `outputTokens` | Tokens produced as output |
| `durationMs` | Call duration in milliseconds |
| `metadata` | Additional information (`source: api\|cli`) |
