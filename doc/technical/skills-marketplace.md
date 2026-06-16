# Skills Marketplace Strategy

> See also: [Architecture](architecture.md) · [REST API](api.md) · [Functional Skills](../functional/skills.md)

## Goal

Prepare a future marketplace phase for skills without implementing it prematurely.

The marketplace must solve three concerns cleanly:
- publication of reusable skills
- discovery and installation of published skills
- versioned local use of installed skills inside SoManAgent

This document defines the target model and the implementation slices to follow later.

## Current State

Today SoManAgent treats a skill as a local resource:
- `skills/imported/` contains skills imported from `skills.sh`
- `skills/custom/` contains skills authored inside SoManAgent
- the database stores a single `Skill` row per `slug`
- imported and custom skills can both be edited locally

Current constraints and limits:
- `Skill.slug` is globally unique, so two versions of the same published skill cannot coexist
- an imported skill is effectively converted into a mutable local copy
- there is no durable distinction between:
  - a published skill identity
  - an installed local copy
  - a local override of an imported skill
- discovery is tied to ad hoc `skills.sh` CLI usage rather than a first-class product model

This is sufficient for local experimentation, but not for a marketplace lifecycle.

## Product Direction

The future marketplace should treat publication and local use as different concepts.

Target product behaviors:
- a publisher can expose a reusable skill with stable identity and metadata
- SoManAgent can discover published skills without installing them first
- installing a skill creates a local installed copy pinned to a version
- local custom skills remain possible and are not forced into marketplace semantics
- local edits of an installed marketplace skill must be explicit:
  - either forbidden
  - or converted into a local fork / override
- roles and workflows must resolve a stable installed local skill, not a remote marketplace entry

## Core Domain Model

Do not reuse the current `Skill` entity as the whole marketplace model.

Recommended future split:

### `SkillCatalogEntry`

Represents a published skill identity in a registry-like source.

Suggested responsibilities:
- canonical slug / vendor namespace
- human name and summary
- publisher / owner
- visibility and publication metadata
- list of available versions
- compatibility metadata

Examples:
- `anthropics/code-reviewer`
- `somanagent/tech-planning`

### `SkillCatalogVersion`

Represents one published immutable version of a catalog entry.

Suggested fields:
- semantic version or immutable release identifier
- release notes / changelog summary
- compatibility constraints
- content digest
- source archive or fetch reference

### `InstalledSkill`

Represents the local installed copy actually used by SoManAgent.

Suggested fields:
- local id
- catalog identity if marketplace-backed, nullable if purely local
- installed version
- install source
- local file path
- effective content snapshot
- installation status
- update availability snapshot

This is the object roles and workflows should ultimately point to.

### `LocalSkillOverride` or explicit fork model

If local editing of installed marketplace skills is kept, it must not silently mutate the published identity.

Preferred approaches:
- safest: installed marketplace skills are read-only, and “Customize” creates a local fork
- acceptable: installed marketplace skills can be overridden locally, but SoManAgent tracks that state explicitly

The current “import then edit in place” model should not survive into marketplace mode.

## Versioning Rules

### Publishing

- published versions are immutable
- updating a published skill means publishing a new version
- the catalog entry remains stable, the version changes

### Installing

- installation is pinned to one exact version
- update checks compare the installed version to the newest compatible version
- updating is an explicit local action, not a silent replacement

### Runtime Resolution

- workflows and roles must resolve to a local installed version
- runtime must never depend on a live remote fetch
- execution must still work offline once the skill is installed locally

## Publication Model

The marketplace phase should not start with full community publishing.

Recommended rollout:

1. Registry-backed discovery only
2. Local install / update / remove
3. First-party publication flow
4. Third-party publication only when trust, metadata, and compatibility rules are defined

This avoids overbuilding moderation and trust mechanics before the install model is stable.

## Discovery Model

Discovery should become a first-class backend capability, not just raw CLI output.

Target discovery data:
- canonical slug
- name
- short description
- publisher
- latest version
- tags / categories
- supported runtimes or compatibility hints
- installation state in the local workspace

Frontend expectations:
- searchable catalog page
- filters by source / owner / tag / installed state
- entry detail showing versions and install status

## Local Filesystem Strategy

The filesystem remains important even with a marketplace.

Recommended direction:
- keep installed skills materialized on disk
- keep marketplace-installed skills under a dedicated subtree distinct from pure local custom skills
- keep local custom authored skills clearly separated

Suggested future layout:

```text
skills/
├── marketplace/
│   └── anthropics/
│       └── code-reviewer/
│           └── 1.2.0/
│               └── SKILL.md
├── custom/
│   └── my-skill/
│       └── SKILL.md
└── forks/
    └── anthropics-code-reviewer-local/
        └── SKILL.md
```

This is only a target direction, not a mandatory final tree.

## Compatibility and Safety

The marketplace will need explicit compatibility checks before install/update:
- supported SoManAgent version range
- required skill format version
- optional runtime requirements

Later phases may also need:
- signature or digest verification
- trust level for publishers
- explicit unsafe capability flags

These are not tranche-one requirements, but the data model should leave room for them.

## Migration Impact on Current Model

Three current assumptions should be considered temporary:

1. One database `Skill` row per `slug`
2. Imported skills edited in place
3. Registry source treated as a one-time import rather than a tracked installation

Marketplace work should evolve the model toward:
- local installed skill references
- immutable version snapshots
- explicit fork / override semantics

## Recommended Implementation Slices

Do not try to build the whole marketplace in one pass.

### Slice 1 — Registry and installation model

- introduce a model for marketplace-backed installed skills
- preserve existing local custom skills
- keep current UI working during migration

### Slice 2 — Discovery API and UI

- add backend search/list/detail over registry entries
- add install-state awareness in the UI

### Slice 3 — Versioned installs and updates

- pin installs to a version
- surface available updates
- implement explicit update flow

### Slice 4 — Local customization strategy

- decide and implement read-only installs vs local forks
- make the resulting UX explicit

### Slice 5 — Role/workflow integration

- make role/workflow references resolve to the installed local skill abstraction
- avoid direct hidden dependence on mutable raw slugs

## Backlog Follow-up

The marketplace preparation task is complete when:
- this target model exists in the documentation
- the gaps with the current implementation are explicit
- later implementation can be split into concrete tasks without redefining the strategy

The actual implementation must now be handled as separate backlog items, kept behind current priorities.
