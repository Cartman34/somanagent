# Code Conventions

This document is the authoritative reference for all code conventions in SoManAgent.
It applies to `backend/src/`, `frontend/src/`, and `scripts/`.

---

## Author Header

Every code file must carry an explicit author header for Florent HAZARD `<f.hazard@sowapps.com>`.

Expected syntax by file type:

- PHP:
```php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
```
- TypeScript / TSX / CSS:
```ts
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
```
- Shell scripts:
```bash
# Author: Florent HAZARD <f.hazard@sowapps.com>
```

For PHP files, place the author block immediately after `<?php`, before `declare(strict_types=1);` when that directive exists.

---

## PHPDoc

PHPDoc is required on:
- every public PHP method (unless truly trivial)
- non-trivial private helpers

When a Symfony method uses both a PHPDoc block and PHP attributes such as `#[Route(...)]`, keep the order:
1. PHPDoc
2. attribute(s)
3. method declaration

```php
/**
 * Creates a project from the request payload.
 */
#[Route('/api/projects', methods: ['POST'])]
public function create(Request $request): JsonResponse
{
    // ...
}
```

---

## JSDoc / TSDoc

JSDoc/TSDoc is mandatory on:
- every exported component, hook, function, and utility
- every non-trivial internal helper

The documentation must explain the role, the important inputs/outputs, and any side effects or behavioral constraints worth knowing before reuse. Avoid comments that merely paraphrase the code line by line.

---

## Translations

User-facing text must **not** be hardcoded in French in application source code (`.php`, `.ts`, `.tsx`).

Use Symfony translation keys instead. Any French literal in source files is a translation migration gap.

Exceptions:
- `backend/translations/*.yaml` files — French is required there
- Command payloads that carry real user input (e.g. a chat message sent to an agent) may be French

For the detailed domain/key conventions and the persisted-message strategy, see [`translations.md`](translations.md).

---

## Entity CSS Classes

Every DOM element that represents a domain entity must carry a semantic CSS class so that external tools (tests, browser extensions, automation scripts) can locate it reliably without relying on layout classes.

| Pattern | Purpose |
|---|---|
| `item-{slug}` | The element **is** an entity — a card, row, or any wrapper that represents a single entity instance |
| `list-{slug}` | The element **contains a collection** of `item-{slug}` elements for that entity |

**Entity slugs in use:**

| Slug | Entity |
|---|---|
| `ticket` | Story or bug (Ticket) |
| `ticket-task` | Technical task (TicketTask) |
| `agent` | Agent |
| `project` | Project |
| `team` | Team |
| `role` | Role |
| `module` | Project module |
| `feature` | Feature |
| `workflow` | Workflow |
| `workflow-step` | Workflow step |
| `audit-log` | Audit log entry |
| `occurrence` | Aggregated log occurrence |
| `log-event` | Raw log event |
| `token-usage` | Token usage entry |
| `agent-execution` | Agent task execution |
| `ticket-log` | Ticket discussion log (comment, reply) |
| `skill` | Skill |
| `chat-message` | Chat message |

**Rules:**
- Apply `item-{slug}` on the outermost element of the entity representation — do not add it to inner wrappers.
- Apply `list-{slug}` on the direct container of the items — the element whose children are `item-{slug}` nodes.
- When an entity can be one of several types (e.g. `Ticket | TicketTask`), resolve the slug dynamically: `isTicket(entity) ? 'item-ticket' : 'item-ticket-task'`.
- When introducing a new entity slug, update this table in the same change.
- These classes carry no visual style — they are purely semantic.

---

## Controllers

Controllers are **thin**: they decode the request, call a service, and return JSON. Business logic belongs in services.

---

## Services

Services contain the business logic and call the repositories.

```php
public function create(string $name, ?string $description): Project
{
    $project = new Project($name, $description);
    $this->em->persist($project);
    $this->em->flush();
    $this->audit->log(AuditAction::ProjectCreated, 'Project', ...);
    return $project;
}
```

---

## Entities

Entities use **UUID v7** generated in the constructor, **Doctrine attributes** as metadata, and **lifecycle callbacks** for `updatedAt`.

```php
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
```

---

## Ports and Adapters

Adapters implement the port. Selection is handled via `AgentPortRegistry` (Symfony tagged services) for AI agents, and via direct injection for VCS.

---

## Development Command Rule

When a project script already exists in `scripts/`, use it in priority over direct container commands.

- prefer `php scripts/console.php cache:clear` over `docker exec ... bin/console cache:clear`
- prefer `php scripts/logs.php worker` over raw `docker logs ...`
- prefer `php scripts/node.php type-check` over a repeated `docker exec ... npm run type-check`
- prefer `php scripts/db.php query "SELECT ..."` over a repeated `docker exec ... psql -c "SELECT ..."`

Direct Docker commands remain acceptable only when no script covers the operation.

Symfony command descriptions, argument help, option help, and console UI output must be written in English. French is reserved for the web interface and end-user surfaces.
