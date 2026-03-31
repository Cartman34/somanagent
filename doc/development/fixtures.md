# Fixtures

> See also: [Installation](installation.md) · [Scripts](scripts.md) · [Workflows](../functional/workflows.md)

Fixtures are sample seed data used to bootstrap a predictable local environment for development and manual testing.

Project rule:

- fixture data must reflect the current product model, not a temporary shortcut
- when a fixture encodes a reference workflow, that workflow must stay documented here
- if the implementation temporarily keeps a legacy field for migration reasons, the fixture doc must state the target model explicitly

## Loading fixtures

Typical reset flow:

```bash
php scripts/db.php reset --fixtures
```

If the database already exists and you want to force recreation:

```bash
php scripts/db.php reset --fixtures --force
```

## Reference web team fixture

Main fixture file:

- [backend/src/DataFixtures/DevTeamFixture.php](/home/sowapps/projects/somanagent/backend/src/DataFixtures/DevTeamFixture.php)

This fixture provides:

- a complete web development team
- roles and skills
- a catalog of agent actions
- a reusable reference workflow named `Développement web standard`

## Reference workflow: `Développement web standard`

This workflow is the reference example for a web development team.

Step order and intended behavior:

1. `Nouvelle`
   - initial ticket step
   - contains the Product Owner framing action
   - this is the only action flagged `createWithTicket`
2. `Prête`
   - no agent action
   - manual step
3. `Planification`
   - Tech Lead planning action
   - planning creates downstream tasks and dependencies
   - automatic step
4. `Conception graphique`
   - design actions only
   - automatic step
   - if there is no task in this step, progression should continue automatically once the runtime refactor is complete
5. `Développement`
   - developer actions such as PHP and frontend implementation
   - automatic step
6. `Revue`
   - Lead Tech review action
   - automatic step
   - if review succeeds, the ticket progresses to `Terminée`
   - if review fails, the reviewer creates new dependent tasks in the required target step instead of finishing the ticket
7. `Terminée`
   - always present
   - no agent action

## Agent behavior expectations around this fixture

- an agent that needs clarification must ask in the ticket discussion
- an agent that encounters a problem must also express it as one or more questions in the ticket discussion
- after the user answers, task execution is resumed explicitly from the task-level action
