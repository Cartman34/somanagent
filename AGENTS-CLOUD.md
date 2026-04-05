# Agents (Cloud)

Single entrypoint for AI agents working on this repository in cloud environments.

Read only this file first. Read additional files only when the active command or task requires them.

For local development with Claude CLI, see [AGENTS.md](AGENTS.md).

## Core Rules

- Work from the cloud container or deployment environment.
- Use project scripts in `scripts/` when available (compatibility may vary by environment).
- Prefer cloud-native tooling (managed secrets, load balancers, observability platforms).
- Keep environment variables and secrets in your cloud provider's vault (AWS Secrets Manager, Azure Key Vault, etc.).
- Use relative paths in commands. Do not rely on `cd` into subfolders.
- Keep chat updates concise.
- UI text is French, but must go through translation keys.
- Technical source content is English: code, comments, docs in source, PHPDoc/JSDoc/TSDoc, CLI output, commit messages.
- Keep `doc/` up to date when code changes require documentation updates.
- `doc/README.md` is the documentation index. Read it only when documentation is actually needed, then open only the relevant file(s).

## Cloud-Only Connector

**Claude API** is the only available connector in cloud environments.

| Aspect | Details |
|---|---|
| **Connector** | `claude_api` |
| **Endpoint** | `https://api.anthropic.com` |
| **Authentication** | `CLAUDE_API_KEY` (environment variable, from secrets vault) |
| **Availability** | Global, no local installation required |

No Claude CLI, no local file system assumptions, no `local/` directory structure.

## Environment Setup

### Prerequisites

1. **Claude API Key**
   - Obtain from [Anthropic Console](https://console.anthropic.com)
   - Store securely in your cloud provider's vault:
     - AWS: Secrets Manager or Parameter Store
     - Azure: Key Vault
     - GCP: Secret Manager

2. **Network Configuration**
   - Ensure outbound HTTPS to `api.anthropic.com` is allowed
   - No firewall rules blocking port 443

3. **Runtime Configuration**
   ```dockerfile
   # Docker example
   ENV CLAUDE_API_KEY=${CLAUDE_API_KEY}
   
   # Or via runtime secret injection
   docker run --secret claude_api_key -e CLAUDE_API_KEY=/run/secrets/claude_api_key ...
   ```

## Creating Agents

### Via the Web Interface

1. Navigate to **Agents** → **"New agent"**
2. Fill in the form:
   - **Name**: e.g., "Cloud Code Reviewer"
   - **Description**: Describe the agent's purpose
   - **Connector**: Select `claude_api`
   - **Model**: Choose from available models
   - **Configuration**: Set `temperature`, `max_tokens`, `timeout`

### Via the API

```http
POST /api/agents
Content-Type: application/json
Authorization: Bearer <session-token>

{
  "name": "Cloud Code Reviewer",
  "description": "Code review agent for cloud deployment",
  "connector": "claude_api",
  "config": {
    "model": "claude-opus-4-6",
    "max_tokens": 8192,
    "temperature": 0.3,
    "timeout": 120
  },
  "roleId": "uuid-of-reviewer-role"
}
```

## Configuration

### Model Selection

Choose models based on task complexity and cost:

| Model | Throughput | Best For |
|---|---|---|
| `claude-opus-4-6` | High capability | Code review, planning, complex analysis |
| `claude-sonnet-4-6` | Balanced | Code generation, testing, documentation |
| `claude-haiku-4-5` | Fast, cost-efficient | Quick classifications, summaries |

### Timeout Configuration

Set timeouts based on expected task duration and network latency:

- **Quick tasks** (classification): 30–45s
- **Standard tasks** (code generation): 60–90s
- **Complex tasks** (planning, analysis): 120s+

### Temperature by Use Case

| Use Case | Temperature |
|---|---|
| Code review | 0.2 – 0.4 |
| Code generation | 0.5 – 0.7 |
| Documentation | 0.6 – 0.8 |
| Testing | 0.3 – 0.5 |
| Planning | 0.3 – 0.5 |

## Health Checks

Verify Claude API connectivity:

```bash
php scripts/health.php
```

Or via API:
```http
GET /api/health/connectors
```

**Expected response**:
```json
{
  "status": "ok",
  "connectors": {
    "claude_api": true,
    "claude_cli": false
  }
}
```

**Troubleshooting**:
- `claude_api: false` → Check `CLAUDE_API_KEY`, network connectivity, API quotas
- Network timeout → Increase agent `timeout` config or add retry logic

## Rate Limiting & Quotas

### Claude API Limits

- **Requests per minute (RPM)**: Depends on plan
- **Tokens per minute (TPM)**: Depends on plan
- **Concurrent requests**: Typically 10–100 per API key

### Handling Rate Limits

Configure retry logic with exponential backoff:

```php
$config = [
    'max_retries' => 3,
    'retry_delay_ms' => 1000,  // Start at 1s
    'retry_backoff' => 2       // Double each retry
];
```

## Monitoring & Observability

### Agent Execution Metrics

Retrieve execution history:

```http
GET /api/agents/{id}/executions?limit=10
```

**Track**:
- Response time (network latency + model processing)
- Token usage (input + output tokens)
- Error rate and failure modes

### Structured Logging

Enable cloud-native logging:

```php
'logging' => [
    'enabled' => true,
    'level' => 'debug',
    'output' => 'cloudwatch'  // or stackdriver, datadog, etc.
]
```

Log entries include:
- API request/response (redacted API key)
- Latency breakdown
- Token counts
- Error details

## Cost Optimization

### Monitor Token Usage

```http
GET /api/agents/{id}/stats?period=month
```

### Strategies

1. **Use Haiku for fast tasks**: Significantly lower cost
2. **Set `max_tokens` conservatively**: Reduce token generation limits
3. **Batch requests where possible**: Combine multiple calls
4. **Cache SKILL.md content**: Reuse across executions

## Scaling

With `claude_api`, scaling is:
- **Unlimited**: Claude API handles horizontal scaling transparently
- **Cost-based**: Limited by API quotas and budget, not infrastructure

Multiple agents can execute simultaneously without local resource contention.

## Git Rules

- Always use `git add .` unless specific file staging is needed
- Use `git push -u origin <branch>` with no force unless explicitly requested
- Never amend a published commit
- Use `php scripts/github.php` for GitHub operations instead of `gh`

## Conventions Snapshot

- PHPDoc is required on public PHP methods unless they are truly trivial, and on non-trivial private helpers.
- JSDoc/TSDoc is required on exported TypeScript/React code and on non-trivial internal helpers.
- When a Symfony method has both PHPDoc and attributes, keep the order: PHPDoc, attribute, method declaration.
- For detailed conventions, read [`doc/technical/conventions.md`](doc/technical/conventions.md) only when needed.

## Runtime Notes

- API endpoint: `https://<your-cloud-domain>/api`
- Health endpoint: `GET /api/health/connectors`
- All secrets from environment variables (no local files)
- Sync credentials: use cloud provider's credential rotation

Useful checks:

```bash
php scripts/console.php cache:clear
php scripts/health.php
php scripts/node.php type-check
php scripts/logs.php worker --tail 120
```

## Related Documentation

- [Agents (Local)](AGENTS.md)
- [Agents Documentation](doc/functional/agents.md)
- [Key Concepts](doc/functional/concepts.md)
- [Teams and Roles](doc/functional/teams-and-roles.md)
- [Adapters](doc/technical/adapters.md)
