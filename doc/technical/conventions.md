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

## PHP Imports

Always use `use` statements to import classes. Never write fully qualified class names inline (e.g. `\Sowapps\SoManAgent\Entity\Foo::class` or `new \Sowapps\SoManAgent\Entity\Foo()`).

```php
// ✅ correct
use Sowapps\SoManAgent\Entity\Project;

$project = new Project();
self::EXCLUDED = [Project::class];

// ❌ wrong
$project = new \Sowapps\SoManAgent\Entity\Project();
self::EXCLUDED = [\Sowapps\SoManAgent\Entity\Project::class];
```

This applies to `::class` references in constants and arrays as well.

---

## Constants And Static Configuration

Do not scatter repeated domain strings, mode names, state names, or configuration selectors as inline literals across the code.

When a value is not modeled as an enum, promote it to an explicit constant or a static configuration map.

Prefer:
- class constants for repeated identifiers such as modes, scopes, states, engines, channels, or metadata keys
- enum cases for closed sets of domain identifiers — including **CLI command names** (e.g. `BacklogCommandName::ENTRY_MERGE`) and **CLI option names** (`--body-file`, `--agent`, `--type`, etc.) when reused across call sites
- static arrays or `match` mappings for declarative configuration
- one source of truth for allowed values and their related configuration

Avoid:
- duplicated string literals such as `'frontend'`, `'backend'`, `'review'`, `'blocked'` repeated in multiple conditions
- hardcoded CLI command names (`'feature-merge'`, `'merge'`, etc.) or option names (`'body-file'`, `'agent'`) reused at multiple call sites instead of an enum or constant — this is the same rule, applied to command and option identifiers
- imperative selection code built from many small `if` branches when the behavior is configuration-driven
- ad hoc inline lists of allowed values repeated in validation and execution paths

Example:
- prefer a constant like `self::SCOPE_FRONTEND` over a hardcoded `'frontend'`
- prefer `BacklogCommandName::MERGE->value` over a hardcoded `'merge'` (or `'feature-merge'` for internal traces — extend the enum if the value still needs to exist somewhere)
- prefer a static map of scope-to-directories over multiple `if ($scope !== ...)` branches

The goal is to keep behavior declarative, reduce drift between validation and execution, and make future changes local and auditable.

### No Dynamic Class Or Method Dispatch

When the set of targets is closed (a fixed list of classes, methods, or callables), prefer a static mapping over runtime resolution.

Avoid:
- `new $className()`
- `$className::method()`
- `call_user_func([$className, $method], ...)`
- any equivalent pattern that hides the call site from static analysis

Prefer:
- a static map of name → closure that explicitly instantiates and invokes the target, for example `'foo' => static fn() => (new Foo())->run()`
- a `match` expression that returns or invokes the explicit target
- a switch of explicit `new Foo()` / `new Bar()` branches when the set is small

PHPStan and other static analysers must see every call site. Dynamic dispatch breaks `public.method.unused` detection and produces false positives or hides real dead code. Dynamic dispatch is acceptable only when the set of targets is genuinely open (plugin loader, runtime-registered handler); document the reason inline.

---

## Control Flow Blocks

Control flow blocks must always use braces, even for a single statement.

This applies to:
- `if`
- `elseif`
- `else`
- `for`
- `foreach`
- `while`
- `do ... while`

Do not use compact one-line control flow such as:

```php
if (!$file->isFile()) continue;
```

Write it as:

```php
if (!$file->isFile()) {
    continue;
}
```

The block body must be placed on its own indented line.

---

## Abstract Classes

An abstract class name must be prefixed with `Abstract`.

---

## PHP Classes — final keyword

PHP classes must remain extensible by default so unit tests can mock concrete collaborators when no contract exists.

A class may be declared `final` only when one of these conditions is true:

- it is a DTO without business behavior
- it implements at least one PHP interface

Any other PHP class must not be declared `final`.

The use of `final` must never make unit tests harder to write or force tests to avoid normal mocking strategies for concrete collaborators.

---

## PHPDoc

PHPDoc is required on:
- every PHP class, enum, interface, and trait (class-level docblock)
- every public PHP method (unless truly trivial)
- non-trivial private helpers

The class-level docblock must describe the role and responsibility of the class/enum/interface/trait in one or two sentences.
The method/function PHPDoc must describe what the callable does, not just restate its signature or types.
In practice, the first sentence must explain the observable behavior or responsibility of the callable.

Avoid weak PHPDoc such as:
- `Initializes the object.`
- `Constructor.`
- `Getter.`
- `Converts to array.`

Prefer PHPDoc that makes the behavior explicit, for example:
- `Builds the immutable pricing snapshot used by model catalogs.`
- `Rebuilds pricing metadata from the normalized cache payload.`
- `Returns the connector catalog serialized for API responses.`

```php
/**
 * Manages projects: CRUD, module management, team/workflow assignment, and dispatch mode transitions.
 */
class ProjectService
{
    // ...
}
```

PHPDoc must be multi-line. Single-line `/** ... */` is forbidden.

KO:

```php
/** Builds the snapshot. */
public function build(): Snapshot
```

OK:

```php
/**
 * Builds the snapshot.
 */
public function build(): Snapshot
```

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
- every exported type, interface, and const declaration in `.ts` files
- every exported component, hook, function, and utility in `.tsx`/`.ts` files
- every non-trivial internal helper

The documentation must explain the role, the important inputs/outputs, and any side effects or behavioral constraints worth knowing before reuse. Avoid comments that merely paraphrase the code line by line.

---

## Comments

Inline comments primarily document the WHY. The WHAT can also deserve a comment when the code is dense or hard to scan at a glance — a complex regex, a non-trivial algorithm, a multi-step transformation, a tricky bit-manipulation — where a short prose summary genuinely helps a reader grasp the intent before parsing the details. The judgment is: would a future reader save real effort thanks to this comment? If yes, write it.

Avoid the opposite extreme: a comment that mechanically paraphrases a trivial statement adds noise. Avoid annotations tied to call history — which feature triggered the change, who added a method, which ticket motivated it — that belongs in commit messages or the PR description, not in the source.

### Essential comments

Some boundaries demand a comment. The reviewer treats the lack of one as a finding.

- **External integrations.** Any call to an external CLI (codex, tmux, docker, gh, …), any HTTP call to a third-party API, any IPC with a process outside the project — document what is expected, why the exact flags or arguments are used, and what known failure modes the call guards against.
- **System boundaries.** Any contract with a system not owned by the project: file paths outside the working tree, environment variables expected by a sandbox or container, signals, lock files, OS-specific behavior. Name the contract; do not let the reader reconstruct it from intuition.
- **Fragile or non-obvious behavior.** Anything that depends on a deprecated flag, a version-specific quirk, a race window, an observed edge case, or a workaround for an upstream bug. State what the bug is and when the workaround can be removed.
- **Non-obvious invariants.** Any precondition, postcondition, or invariant that the code maintains but that the reader cannot infer from the structure alone. State the invariant and what would break if it were violated.

A useful boundary comment answers three questions:
1. What does the surrounding code expect from this external system?
2. Why this exact form? (flag choice, arg order, retry policy, …)
3. What goes wrong if a future change drops or rewords it?

When in doubt, write the comment. Under-documentation at a boundary is the kind of nit the reviewer should never have to raise.

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
- prefer `php scripts/validate-files.php --with-types <files>` for review-scoped frontend changes, and `php scripts/node.php type-check` for an explicit full frontend container type-check; do not run raw `npx tsc`
- prefer `php scripts/db.php query "SELECT ..."` over a repeated `docker exec ... psql -c "SELECT ..."`

Direct Docker commands remain acceptable only when no script covers the operation.

Symfony command descriptions, argument help, option help, and console UI output must be written in English. French is reserved for the web interface and end-user surfaces.
