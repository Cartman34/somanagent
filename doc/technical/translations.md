# Translations Strategy

> See also: [Architecture](architecture.md) · [REST API](api.md)

## Goal

The application must stop relying on hardcoded French strings in source files.

The target model is:
- translations live in Symfony YAML translation files
- source code uses stable translation identifiers
- persisted user-facing messages can still be translated after they are stored
- frontend and backend follow the same naming and domain conventions

## Current Direction

Symfony translation is enabled with:
- default locale `fr`
- translation files under `backend/translations/`

This is the infrastructure baseline.

It does **not** mean the whole application is already migrated.

## Core Rule

Outside translation files, application source code should not embed French UI or product text.

That applies to:
- controller error payloads
- persisted log titles/messages
- frontend observability messages
- future user-facing backend exceptions

Technical identifiers, route names and internal debug text remain in English.

## Domains

Use Symfony translation domains to separate responsibilities cleanly.

Recommended domains:
- `app`
  generic application UI and API messages
- `logs`
  persisted log titles and messages
- `projects`
- `tasks`
- `agents`
- `teams`
- `roles`
- `skills`
- `workflows`

Start small, but do not overload `messages` with everything.

`logs` must stay separate because persisted observability text has different lifecycle and storage constraints.

## Key Convention

Use dot-separated keys with stable semantic structure.

Recommended shape:
- `<bounded-context>.<resource>.<purpose>`

Examples:
- `projects.error.not_found`
- `tasks.validation.title_required`
- `logs.health.degraded_connectors.title`
- `logs.health.degraded_connectors.message`

Rules:
- lowercase only
- dot-separated
- no UI copy inside the key
- no version suffix in the key
- keep the key stable even if the French wording evolves

## Persisted Messages

Persisted human-facing messages must not rely on already-rendered French text as the canonical value.

For persisted logs and similar data, the canonical payload should become:
- translation domain
- translation key
- translation parameters

Recommended storage shape:
- `title_domain`
- `title_key`
- `title_parameters` (JSON)
- `message_domain`
- `message_key`
- `message_parameters` (JSON)

Rendered text can still be exposed in API responses for convenience, but it should be derived at read time.

This is the only way to:
- translate stored history later
- change wording without rewriting historical rows
- support future locale changes cleanly

## Rendering Strategy

### Backend

At write time:
- persist translation key/domain/parameters

At read time:
- resolve translated strings with Symfony translator
- return both the rendered value and the translation metadata when useful

Suggested API response shape for persisted messages:
- `title`
- `message`
- `i18n`
  - `titleDomain`
  - `titleKey`
  - `titleParameters`
  - `messageDomain`
  - `messageKey`
  - `messageParameters`

This keeps the frontend simple while preserving the canonical translation identity.

### Frontend

Short term:
- frontend may still display backend-rendered strings

Long term:
- if frontend-side localization is introduced, it can use the returned metadata instead of depending only on rendered text

## Dynamic Parameters

Dynamic text must be carried through named translation parameters.

Rules:
- use named placeholders
- pass only scalars or stringable identifiers
- avoid pre-formatting full user-facing sentences in source code

Example:
- key: `logs.health.degraded_connectors.message`
- params:
  - `%connectors%`: `claude_cli, github`

## Migration Strategy

The migration should be incremental.

### Phase 1

Enable and document translation infrastructure.

Done in this slice:
- Symfony translation enabled
- translation files and conventions introduced

### Phase 2

Stop adding new hardcoded French text in source files.

Every new user-facing message must go through translation keys.

### Phase 3

Migrate controller/API error responses to translation keys and translator-based rendering.

### Phase 4

Migrate persisted logs from raw `title` / `message` text to translation metadata plus rendered output.

### Phase 5

Migrate remaining frontend-generated persisted messages to key/domain/params instead of French literals.

Historical rows can keep legacy rendered text during transition, but new writes should move to canonical translation metadata once the schema is ready.

## Review Rule

When reviewing code:
- a French string in source is a migration gap unless it is inside a translation file
- persisted user-facing text must be challenged if it stores rendered language instead of translation metadata

## Immediate Follow-up Tasks

This strategy implies concrete implementation tasks:
- migrate backend controller error payloads to translator-backed keys
- add canonical translation metadata to persisted logs
- migrate frontend observability writes to translation keys and parameters
- expose rendered strings plus i18n metadata in API payloads where needed
