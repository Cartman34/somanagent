# Ports et Adaptateurs

> Voir aussi : [Architecture](architecture.md) · [Configuration](configuration.md) · [Agents IA](../fonctionnel/agents.md)

## Principe

Les adaptateurs permettent de **remplacer une implémentation** sans modifier le code métier. Chaque port est une interface PHP ; les adaptateurs en sont les implémentations concrètes injectées par Symfony.

## AgentPort — Communication avec les IA

**Interface** : `src/Port/AgentPort.php`

```php
interface AgentPort
{
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse;
    public function healthCheck(): bool;
    public function supportsConnector(ConnectorType $type): bool;
}
```

### Sélection de l'adaptateur

`AgentPortRegistry` (tagged services Symfony) résout le bon adaptateur selon le `ConnectorType` de l'agent :

```yaml
# services.yaml
App\Adapter\AI\ClaudeApiAdapter:
    arguments: { $apiKey: '%env(CLAUDE_API_KEY)%' }
    tags: ['app.agent_adapter']

App\Adapter\AI\ClaudeCliAdapter:
    tags: ['app.agent_adapter']
```

Lors de l'exécution d'une étape :
```php
$adapter = $this->registry->getFor($agent->getConnector()); // ConnectorType::ClaudeApi
$response = $adapter->sendPrompt($prompt, $agent->getAgentConfig());
```

### ClaudeApiAdapter (`claude_api`)

- Envoie une requête HTTP POST vers `https://api.anthropic.com/v1/messages`
- Requiert `CLAUDE_API_KEY` dans `.env`
- Retourne un `AgentResponse` avec tokens consommés et durée

### ClaudeCliAdapter (`claude_cli`)

- Exécute `claude --print --model <model> <prompt>` via `Symfony\Component\Process`
- Requiert que le binaire `claude` soit dans le PATH de la machine hôte
- Timeout configurable via `AgentConfig.timeout`

### Ajouter un nouvel adaptateur IA

1. Créer `src/Adapter/AI/MonIaAdapter.php` implémentant `AgentPort`
2. Ajouter la valeur dans l'enum `ConnectorType`
3. Tagguer dans `services.yaml` avec `app.agent_adapter`
4. Le `AgentPortRegistry` le résoudra automatiquement

---

## VCSPort — Intégration Git

**Interface** : `src/Port/VCSPort.php`

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

- Authentifié via `GITHUB_TOKEN`
- Base URL : `https://api.github.com`
- Utilise l'API GitHub v3 (header `Accept: application/vnd.github.v3+json`)

### GitLabAdapter

- Authentifié via `GITLAB_TOKEN`
- Base URL configurable (pour les instances self-hosted) via `GITLAB_URL`
- Utilise l'API GitLab v4

### Utilisation

Les adaptateurs VCS ne sont pas injectés via un registry : l'adapter à utiliser est déterminé par la configuration du module (à venir). Pour l'instant, il est injectable directement dans les services.

---

## SkillPort — Import de skills

**Interface** : `src/Port/SkillPort.php`

```php
interface SkillPort
{
    public function import(string $ownerAndName): array; // retourne métadonnées du SKILL.md
    public function search(string $query = ''): array;
}
```

### SkillsShAdapter

- Lance `npx skills add <owner/name>` dans `skills/imported/`
- Parse le frontmatter YAML + corps Markdown du `SKILL.md` résultant
- Retourne `['slug', 'name', 'description', 'content', 'filePath', 'originalSource']`

**Prérequis** : Node.js et npm doivent être disponibles (dans le conteneur `node`).

---

## Construction du Prompt

Le `ValueObject\Prompt` assemble le texte final envoyé à l'agent :

```
# Instructions du skill

[contenu du SKILL.md]

---

# Contexte

**module** : api-php
**pr_diff** : [diff du code]

---

# Tâche

[instruction spécifique de l'étape du workflow]
```

`Prompt::build()` retourne ce texte. Il est passé à `AgentPort::sendPrompt()`.

---

## AgentResponse

Objet immuable retourné par les adaptateurs :

| Propriété | Description |
|---|---|
| `content` | Texte de la réponse de l'IA |
| `inputTokens` | Tokens consommés en entrée |
| `outputTokens` | Tokens produits en sortie |
| `durationMs` | Durée de l'appel en millisecondes |
| `metadata` | Informations supplémentaires (`source: api\|cli`) |
