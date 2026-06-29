# Spec maintenance — SoManAgent specifics

The generic spec conventions (file layout, header, mandatory section, lifecycle, history-by-git) live in the toolkit: [`scripts/toolkit/doc/documenting/spec-maintenance.md`](../../scripts/toolkit/doc/documenting/spec-maintenance.md). This file adds only SoManAgent's specifics.

- **Language**: local specs are written in **French**.
- **Header**: includes the authoring **agent code** and the **covered backlog tasks**:

```markdown
**Création :** YYYY-MM-DD — Agent <code>
**Mise à jour :** YYYY-MM-DD — Agent <code>
**Tâches backlog couvertes :** `<feature-slug>` (et `<feature-slug>/<task-slug>` pour une tâche)
```

- **DCM**: when a spec needs a DCM, adopt the multi-file folder layout (`<slug>-spec/`) and place the DCM at its root as `<slug>-dcm.md`, sharing the same header conventions. No `dcm/` subfolder.
- **Follow-up tasks**: a spec may end with a `## Tâches de suivi` section listing the backlog work derived from it (initial enumeration only; the board stays the live source of task state).
- **Lifecycle owner**: the manager role owns the spec lifecycle — audits the spec against code and `doc/`, migrates remaining normative content, creates developer tasks for gaps, then deletes the spec.
