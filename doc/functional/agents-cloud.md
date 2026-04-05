# Agents (Cloud)

Deployment and agent configuration guide for cloud environments.

For local development with Claude CLI, see [AGENTS.md](/AGENTS.md).

## Core Rules (Cloud)

- Work from the cloud container or deployment environment.
- Use project scripts in `scripts/` when available (compatibility may vary by environment).
- Prefer cloud-native tooling (managed secrets, load balancers, observability platforms).
- Keep environment variables and secrets in your cloud provider's vault (AWS Secrets Manager, Azure Key Vault, etc.).
- Always reference API endpoints relative to your cloud deployment domain.
- Technical source content is English: code, comments, docs in source, PHPDoc/JSDoc/TSDoc, CLI output, commit messages.
- Keep `doc/` up to date when deployment patterns change.

## Agent Connector: Claude API

| Aspect | Details |
|---|---|
| **Connector** | `claude_api` |
| **Endpoint** | `https://api.anthropic.com` |
| **Authentication** | `CLAUDE_API_KEY` (environment variable, stored in secrets vault) |
| **Availability** | Global, no local installation required |
| **Latency** | Network-dependent (typically 1–2s per request) |

Claude API is the **only connector available in cloud environments**. No local Claude CLI setup is needed.

## Environment Setup

### Prerequisites

1. **Claude API Key**
   - Obtain from [Anthropic Console](https://console.anthropic.com)
   - Store in your cloud provider's secrets manager
   - Example (AWS): `aws secretsmanager get-secret-value --secret-id somanagent/claude-api-key`

2. **Network Configuration**
   - Ensure outbound HTTPS to `api.anthropic.com` is allowed
   - No firewall rules blocking port 443
   - If behind a proxy, configure appropriately in your deployment

3. **Runtime Configuration**
   ```dockerfile
   # Docker example
   ENV CLAUDE_API_KEY=${CLAUDE_API_KEY}
   
   # Or via runtime secret injection
   docker run --secret claude_api_key -e CLAUDE_API_KEY=/run/secrets/claude_api_key ...
   ```

### Health Checks

Verify Claude API connectivity:

```bash
php scripts/health.php
```

Or via API:
```http
GET /api/health/connectors
```

Expected response:
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

## Creating Agents

### Via the Web Interface

1. Navigate to **Agents** → **"New agent"**
2. Fill in the form:
   - **Name**: e.g., "Production Code Reviewer"
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
  "name": "Production Code Reviewer",
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

## Agent Configuration

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

Example:
```json
{
  "timeout": 120
}
```

### Temperature by Use Case

| Use Case | Temperature |
|---|---|
| Code review | 0.2 – 0.4 |
| Code generation | 0.5 – 0.7 |
| Documentation | 0.6 – 0.8 |
| Testing | 0.3 – 0.5 |
| Planning | 0.3 – 0.5 |

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
- Model throughput

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
- Error details and stack traces

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

## Cloud Deployment Checklist

- [ ] `CLAUDE_API_KEY` in secrets manager
- [ ] Network egress to `api.anthropic.com:443` allowed
- [ ] Health check passes: `GET /api/health/connectors`
- [ ] Agent `timeout` appropriate for cloud latency
- [ ] Monitoring and logging configured
- [ ] Model selection finalized
- [ ] Rate limit handling and retries configured
- [ ] Cost monitoring dashboard set up

## Related Documentation

- [Agents (Local)](../../AGENTS.md)
- [Key Concepts](concepts.md)
- [Teams and Roles](teams-and-roles.md)
- [Adapters](../technical/adapters.md)
- [Anthropic API Docs](https://docs.anthropic.com)
