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

Translation keys must never be built dynamically by concatenating strings at runtime. Always use a static mapping (a `match`, a `const` array, or an equivalent) that lists every key explicitly. This makes keys statically analysable and prevents silent fallbacks from hiding missing translations.

---

## Semantic CSS Classes

DOM elements must carry semantic CSS classes so that external tools (tests, browser extensions, automation scripts) can locate them reliably without depending on layout or visual classes. These classes may or may not carry visual styles — both are valid. Their primary purpose is identification.

Two independent systems coexist, with distinct naming conventions:

### `item-{type}` / `list-{type}` — typed elements

`{type}` identifies the **type** of the represented concept — usually a domain entity, but not exclusively.

| Pattern | Purpose |
|---|---|
| `item-{type}` | The element **represents** a single instance of `{type}` — a card, row, drawer, or any standalone block |
| `list-{type}` | The element **contains a collection** of `item-{type}` elements — the direct parent of the items |

**Rules:**
- Apply `item-{type}` on the outermost element of the representation — not on inner wrappers.
- Apply `list-{type}` on the direct container of the items.
- When a type can vary at runtime (e.g. `Ticket | TicketTask`), resolve it dynamically: `isTicket(entity) ? 'item-ticket' : 'item-ticket-task'`.
- When introducing a new type, add it to the table below in the same change.

**Types in use:**

| Type | Concept |
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

### `section-{region}` — functional regions

`{region}` identifies a **functional region** within a UI surface — a role in the interface, not a data type.

| Pattern | Purpose |
|---|---|
| `section-{region}` | The element **is a functional region** of the interface — a control group, a labelled block, a structural area |

**Rules:**
- Apply the class on the outermost element of the region.
- Sub-regions with their own distinct role get their own `section-{region}` class.

**Common region examples:**

| Class | Element | Purpose |
|---|---|---|
| `section-title` | `<p>` or heading | Primary label of a UI section block |
| `section-legend` | `<p>` | Secondary label or contextual caption inside a section |

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
