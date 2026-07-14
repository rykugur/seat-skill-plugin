# Project Overview

`fside/seat-skill-notifications` is a SeAT v5 plugin that posts a native Discord
notification when a corporation member finishes training a skill level.

The plugin does not call ESI. It reads SeAT's already-synced skill data from
`character_skills`, compares it against plugin-owned snapshots, records
completions, and dispatches through SeAT's notification framework.

## Current status

Phase 1 is implemented and verified:

- `skillnotify:scan` detects trained-skill level increases.
- First scan per character silently baselines current skills.
- `skillnotify:seed` can baseline all current characters before enabling
  notifications.
- `fside_skill_completed` is registered as a SeAT notification alert.
- Discord delivery uses SeAT notification groups and Discord integrations.
- Notification groups are scoped by character or corporation affiliation.
- Completion rows are recorded durably in `skillnotify_skill_completions` and
  retried on later scans if still pending.
- A local SeAT v5 Docker smoke test delivered a Discord message successfully.

## Deferred work

Later phases from the original design remain open:

- Skill plans and plan assignments.
- Milestone notifications for completed plans.
- Empty or ending-soon skill queue warnings.
- Read-only dashboard and SeAT plugin UI.
- Optional richer filtering or batching modes.

