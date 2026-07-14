# FSIDE Skill Notifications Wiki

This wiki is the maintained knowledge layer for the `fside/seat-skill-notifications`
SeAT plugin. It summarizes the implementation, operational workflow, and
verified decisions so future work does not need to re-read the full source tree.

## Core pages

- [Project Overview](project-overview.md) - purpose, current status, scope, and
  deferred work.
- [Architecture](architecture.md) - package structure, service provider,
  commands, models, and data flow.
- [Notification Flow](notification-flow.md) - how skill completions become
  native SeAT Discord notifications.
- [Installation And Operations](installation-and-operations.md) - Composer
  install paths, migrations, worker restarts, seeding, and SeAT UI setup.
- [Development And Testing](development-and-testing.md) - local dev stack,
  unit/feature tests, and the verified Docker smoke test.
- [Decisions And Gaps](decisions-and-gaps.md) - key technical decisions,
  resolved SeAT v5 quirks, and open future phases.
- [Log](log.md) - chronological maintenance log for this wiki.

## Source documents

- [README](../../README.md) - user-facing installation and usage guide.
- [DEVNOTES](../../DEVNOTES.md) - verified SeAT v5 class, column, and API
  signatures.
- [Design spec](../superpowers/specs/2026-06-25-fside-skill-notifications-design.md)
  - original Phase 1 design and later-phase outline.
- [Implementation plan](../superpowers/plans/2026-06-26-fside-skill-notifications-phase1.md)
  - task-level build plan.
