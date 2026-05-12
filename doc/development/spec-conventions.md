# Spec Conventions

Conventions for writing and maintaining local specifications stored under `local/specs/`.

## Scope

Applies to every file under `local/specs/`, including single-file specs and multi-file spec folders. `local/` is not committed, but specs are shared with other agents through the project workflow and must follow a stable structure.

## Language

Local specs are written in French. This convention document is in English; the spec excerpts shown below use French because that is how they appear in actual spec files.

## File layout

A spec is either:

- **Single file**: `local/specs/<slug>-spec.md` for a focused spec.
- **Multi-file folder**: `local/specs/<slug>-spec/` containing `00-index.md` plus thematic files prefixed `01-`, `02-`, …, when the spec is large enough to split.

The choice is left to the author based on size and topical breakdown. A multi-file spec must always include `00-index.md` at the root of the folder.

## Header

Every spec (or the `00-index.md` of a multi-file spec) starts with these three header lines, right after the document title:

```markdown
**Date :** YYYY-MM-DD — Agent <code>
**Tâches backlog couvertes :** <feature-slug(s) ou <feature-slug>/<task-slug>>
**Statut :** draft | validée | implémentée
```

- `Date` is the creation or last major-refactor date in ISO format, followed by the agent code that authored the change.
- `Tâches backlog couvertes` lists the backlog slugs (and `<feature-slug>/<task-slug>` references) the spec covers.
- `Statut` is one of `draft`, `validée`, `implémentée`.

## Mandatory sections

After the header, every spec includes these sections in this order, with French titles:

- `## Objectif` — what the spec aims to achieve.
- `## Règle de non-régression` — invariants that must not be broken by future changes.
- `## Conventions` — naming, paths, and project-specific conventions used by the spec.

Additional sections are added freely based on the spec's needs.

## No "Spec history" section

Specs describe the current intended state. Past decisions are not duplicated in the spec body — git history, commit messages, and PR descriptions are the source of truth for change tracking. When a spec is refactored or significantly amended, update the `Date` line of the header to reflect the latest state instead of appending a dated history block.

## Follow-up backlog tasks

When a spec references backlog work that remains to be created or completed, list it in a dedicated final section:

```markdown
## Tâches de suivi

- `<feature-slug>` — courte description du suivi.
- `<feature-slug>/<task-slug>` — courte description pour une task scoppée.
```

This section keeps follow-up tracking separate from the spec body and from any historical context. Items here describe outstanding work that should land in the backlog; they are not used as a retrospective log.

## Multi-file specs

For specs under `<slug>-spec/`:

- `00-index.md` carries the header and a table of contents that lists each thematic file with a one-line description and its dependencies on other files of the same spec.
- Each subsequent file starts with a `# <Titre>` and may use any internal structure.
- A dedicated DCM file, when present, lives next to the index as `<slug>-dcm.md` and shares the same header conventions.
