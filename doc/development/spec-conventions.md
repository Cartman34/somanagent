# Spec Conventions

Conventions for writing and maintaining local specifications stored under `local/specs/`.

## Scope

Applies to specs created from this convention forward. Existing specs may keep their previous format until intentionally refactored — they are not retrofitted automatically.

## Language

Local specs are written in French. This convention document is in English; the spec excerpts below use French because that is how they appear in actual spec files.

## File layout

A spec is either:

- **Single file**: `local/specs/<slug>-spec.md` — used when the spec is focused and does not need a DCM.
- **Multi-file folder**: `local/specs/<slug>-spec/` containing `00-index.md` plus thematic files prefixed `01-`, `02-`, …, and optionally a DCM file `<slug>-dcm.md` at the root of the folder.

A spec adopts the multi-file layout as soon as it needs a DCM, since the DCM and the index then live as siblings inside the same folder. There is no `dcm/` subfolder.

## Header

Every spec (or the `00-index.md` of a multi-file spec) starts with these header lines, right after the document title:

```markdown
**Création :** YYYY-MM-DD — Agent <code>
**Mise à jour :** YYYY-MM-DD — Agent <code>
**Tâches backlog couvertes :** `<feature-slug>` (et `<feature-slug>/<task-slug>` pour une tâche au sein d'une feature parente)
```

- `Création` is the date the spec was first written, followed by the agent code that authored it. Set once, never edited.
- `Mise à jour` is optional. Add it only when the spec is significantly refactored; the date is the refactor date and the agent code is the author of the refactor. Repeated update lines are allowed when several major refactors have happened.
- `Tâches backlog couvertes` lists the backlog feature slugs (and optional `<feature-slug>/<task-slug>` references) that the spec is written for.

No status, validation, or workflow state appears in the header. Spec progress is tracked through the backlog, not through metadata on the spec itself.

## Mandatory section

After the header, every new spec includes:

- `## Objectif` — what the spec aims to achieve.

The following section is strongly recommended whenever the spec replaces or modifies an existing behavior:

- `## Règle de non-régression` — invariants that must not be broken by future changes.

Additional sections (`## Conventions`, `## Architecture`, …) are added freely based on the spec's needs.

## History is tracked by git

Spec changes are not annotated inside the spec body. Git history, commit messages, and PR descriptions are the source of truth for change tracking.

When a spec is significantly refactored, update the `Mise à jour` header line; do not append a dated entry to the body. There is no `## Spec history` section.

## Follow-up backlog tasks

When a spec identifies backlog work that needs to be created or completed to implement it, list that work in a dedicated final section:

```markdown
## Tâches de suivi

- `<feature-slug>` — courte description du suivi.
- `<feature-slug>/<task-slug>` — courte description du suivi.
```

This section is the initial enumeration of work derived from the spec. It is not updated to reflect backlog progress — the backlog board is the live source of truth for task state. The section remains as the first version of the plan, even after tasks have been started, merged, or cancelled.

## Multi-file specs

For specs under `<slug>-spec/`:

- `00-index.md` carries the header above and a table of contents listing each thematic file with a one-line description and its dependencies on the other files of the same spec.
- Each subsequent file starts with a `# <Titre>` and may use any internal structure.
- A DCM file, when present, is placed at the root of the spec folder as `<slug>-dcm.md` and shares the same header conventions. There is no `dcm/` subfolder.
