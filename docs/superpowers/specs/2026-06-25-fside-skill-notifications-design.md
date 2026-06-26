# FSIDE Skill Notifications — Design

**Date:** 2026-06-25
**Status:** Phase 1 approved for implementation; Phases 2–4 outlined.
**Package:** `fside/seat-skill-notifications` (PHP namespace `Fside\SkillNotifications\`)
**Target:** SeAT v5 (Docker v5), MariaDB.

## Purpose

Fly Sideways (FSIDE) wants a SeAT plugin whose **primary deliverable is a Discord
notification when a corp member finishes training a skill** (e.g. "*Korgoroth*
completed *Caldari Battleship V*"). The notification must look like SeAT's own
native Discord embeds (the same style as the existing "SeAT Contract Monitor"
alerts), and must be dispatched through SeAT's built-in notification framework so
admins configure the target channel the SeAT-native way.

Skill plans, milestone alerts, queue warnings, and a reporting dashboard are
desired secondary features, deferred to later phases. This document specs
**Phase 1 (the skill-completion notification engine) in full** and outlines the
rest.

## Scope

**In scope (Phase 1):**
- Detect skill-level completions for all characters SeAT has skill data for.
- Dispatch one native Discord embed per completion via SeAT's notification
  framework (notification groups).
- Silent per-character first-run baseline so installing the plugin (or a member
  joining later) does not spam a character's entire skill history.

**Out of scope (Phase 1, see Later Phases):**
- Skill plans, plan-assignment, milestone notifications.
- Skill-queue warnings (empty / ending-soon).
- Reporting dashboard / web UI.
- Per-notification-group filtering (all completions go to enabled groups in
  Phase 1; the architecture is built so filtering layers on later).
- Batched "summary" embeds (one embed per completion in Phase 1; dispatcher
  structured so batching can be switched on without touching the diff engine).

## Key decisions & rationale

- **Read SeAT's synced data, never call ESI directly.** SeAT already owns the
  ESI OAuth / token-refresh / rate-limit lifecycle and stores `character_skills`.
  We consume that.
- **Detect completions by snapshot-diff of `character_skills.trained_skill_level`**,
  not by watching `character_skillqueue.finish_date` and not via model
  observers. Diffing is robust against SeAT's sync timing, paused/reordered
  queues, ESI pruning finished queue entries, and multi-level jumps. A skill
  that starts and finishes between two syncs can never be "missed."
- **Use SeAT's built-in notification framework** (register an alert in
  `notifications.alerts.php`, formatter extends `AbstractDiscordNotification`,
  dispatch via the `NotificationDispatchTool` trait), not a custom encrypted
  webhook `DiscordNotifier`. This gives native embeds and SeAT-native channel
  configuration for free.
- **MariaDB only.** SeAT v5 officially supports MariaDB only; the plugin lives
  in SeAT's own default DB connection and never opens its own. Migrations and
  queries are written with portable Laravel schema/query-builder (incl.
  `upsert()`) as hygiene, but MariaDB is the build/test target.

## Architecture

A real SeAT v5 package with a service provider extending `AbstractSeatPlugin`.
The provider registers: migrations, the scheduled scan command (via an
`AbstractScheduleSeeder`), and the notification alert config (via
`mergeConfigFrom`). No routes/views/sidebar/permissions in Phase 1 (those arrive
with the Phase 2 UI).

```
src/
  SkillNotificationsServiceProvider.php   extends AbstractSeatPlugin
  Config/
    notifications.alerts.php              registers the fside_skill_completed alert
  Console/
    Scan.php                              artisan: skillnotify:scan
    Seed.php                              artisan: skillnotify:seed (optional backfill)
  Database/
    Seeders/ScheduleSeeder.php            AbstractScheduleSeeder for skillnotify:scan
    migrations/
      ..._create_skillnotify_skill_snapshots.php
  Models/
    SkillSnapshot.php                     Eloquent model over skillnotify_skill_snapshots
  Services/
    SkillDiff.php                         pure diff: (snapshot, current) -> completions[]
  Notifications/
    Discord/SkillCompleted.php            extends AbstractDiscordNotification
  resources/lang/en/alerts.php            translation tokens
```

Tables are namespaced `skillnotify_*` and contain **no foreign keys into SeAT
core tables** (per SeAT package-development guidance, to avoid coupling our
migrations to core migration ordering).

## Data model

One Phase 1 table:

```
skillnotify_skill_snapshots
  character_id        bigint      ┐ composite primary key
  skill_id            int         ┘
  trained_skill_level tinyint        last level we have already accounted for
  updated_at          timestamp
```

- No settings table: notification routing lives in SeAT's notification-group
  config, not ours.
- "First run" is detected **per character** by absence of any snapshot rows for
  that `character_id`.

## Detection engine

### Pure diff (`Services/SkillDiff`)

A pure function with no DB or framework dependency:

```
diff(array $snapshot, array $current): Completion[]
  // $snapshot, $current: skill_id => trained_skill_level
  // returns one Completion{skill_id, from_level, to_level} per skill where
  // current > snapshot (absent snapshot => from_level 0)
```

This is the highest-value unit under test and is DB-agnostic.

### Scheduled command (`Console/Scan` → `skillnotify:scan`)

Registered via `ScheduleSeeder` (`AbstractScheduleSeeder`):
- expression: `*/15 * * * *` (every 15 min; intended to run after SeAT's skill sync)
- `allow_overlap = false`
- `allow_maintenance = false`

The seeder only sets the **default** cadence. SeAT stores scheduled jobs in its
`schedules` table and exposes them in its schedule-management UI, so an admin can
change the `skillnotify:scan` expression after install without code changes. The
15-minute value is therefore a default, not a hard-coded constant. (Verify the
v5 schedule-edit path during implementation planning.)

Per run, **chunked per character, one DB transaction per character**:

1. Load current `(skill_id => trained_skill_level)` from `character_skills` for
   the character.
2. Load the character's `skillnotify_skill_snapshots` rows.
3. **If no snapshot rows exist** → seed silently from current state; emit no
   notifications; done for this character (baseline).
4. Else compute completions via `SkillDiff::diff(snapshot, current)`.
5. Dispatch one notification per completion.
6. `upsert` the snapshot rows to current levels.

Ordering guarantee: the snapshot for a character advances only **after** that
character's notifications are queued, so a crash mid-run re-notifies at most one
character's batch rather than silently missing completions.

Edge cases:
- Level *decrease* (should not happen): snapshot is lowered, no notification.
- Character with unsynced / missing skill data: skipped this run, retried next.

Phase 1 scope = all characters with skill data. Per-group filtering is deferred
(see Later Phases) and does not change this engine.

## Notification

### Registration (`Config/notifications.alerts.php`)

Merged into core config in the service provider via `mergeConfigFrom`:

```php
'fside_skill_completed' => [
    'label'    => 'skillnotify::alerts.skill_completed',
    'handlers' => [
        'discord' => Fside\SkillNotifications\Notifications\Discord\SkillCompleted::class,
        // 'slack' / 'mail' may be added later, same pattern
    ],
]
```

### Formatter (`Notifications/Discord/SkillCompleted`)

Extends `Seat\Notifications\Notifications\AbstractDiscordNotification` and
implements:

```php
protected function populateMessage(DiscordMessage $message, $notifiable)
```

It mutates the provided `$message` (does not new up its own) to build a native
embed matching the Contract-Monitor style:

- **Author/header:** "FSIDE Skill Monitor"
- **Title:** "A member finished training a skill"
- **Fields:** Character · Corporation · Skill (name resolved via SDE `InvType`,
  `skill_id → typeName`) · Level (roman numeral, e.g. *V*) · Skill Points ·
  Completed (timestamp)

### Dispatch

From `Console/Scan` via the `Seat\Notifications\Traits\NotificationDispatchTool`
trait, targeting whichever **notification groups** an admin has enabled the
"Skill Completed" alert on. Admin setup: Settings → Notifications → add a Discord
channel/integration → enable the *Skill Completed* alert. One embed per
completion; the dispatch path is isolated so a future "batch into one summary
embed" mode requires no change to `SkillDiff` or `Scan`.

> Implementation-planning note: the exact SeAT v5 signatures for
> `AbstractDiscordNotification`, `DiscordMessage`, `NotificationDispatchTool`,
> and the notification-group dispatch entry point must be verified against the
> installed v5 source before coding, as the public docs describe v4. The README
> in this repo flags several class/column names as unverified guesses; treat
> those as needing a `grep` pass through `vendor/eveseat/*` on the running
> instance.

## First-run seeding / backfill

- **Default:** per-character silent baseline (step 3 above). Installing the
  plugin and running the first scan seeds everyone quietly; real changes notify
  thereafter. New members joining SeAT later are baselined on their first scan.
- **Optional `skillnotify:seed`:** pre-seed all characters immediately at
  install, before any notification group is enabled — belt-and-suspenders
  against an admin enabling a channel before the first scan runs.

## Dev environment & testing

- **Docker SeAT v5** via the official `docker-compose`. The plugin is mounted as
  a Composer **path repository** so edits are live. Iterate with
  `php artisan migrate` and `php artisan config:clear`.
- **Tests (TDD order):**
  1. **Unit** — `SkillDiff::diff` over hand-built snapshot/current arrays
     (new skill, level-up, multi-level jump, no change, decrease). No DB.
  2. **Feature** — `skillnotify:scan` against a test DB: seed `character_skills`,
     run once (asserts silent baseline, zero notifications), bump a
     `trained_skill_level`, run again, assert exactly one completion.
  3. **Dispatch** — Laravel `Notification::fake()` to assert `SkillCompleted`
     fires with the expected embed fields, without hitting real Discord.

## Success criteria (Phase 1)

- Installing the plugin and running `skillnotify:scan` once produces **zero**
  notifications (silent baseline) even though characters have full skill history.
- After a member trains a new skill level and SeAT syncs it, the next
  `skillnotify:scan` produces **exactly one** native Discord embed to each
  enabled notification group, naming the character and the completed skill+level.
- Re-running `skillnotify:scan` with no new completions produces zero
  notifications (idempotent).
- The diff engine has unit tests covering new/level-up/multi-jump/no-change/
  decrease; the command has a feature test for baseline-then-completion.

## Later phases (outline)

- **Phase 2 — Skill plans + UI:** tables `skillnotify_plans`,
  `skillnotify_plan_skills`, `skillnotify_plan_assignments`; CRUD routes/views/
  sidebar entry; permissions `skillnotify.plans.manage` / `skillnotify.plans.view`.
  Consider paste-import of EVE's in-game skill plan format.
- **Phase 3 — Milestone notifications:** new alert when an assigned plan becomes
  fully trained (optionally per-skill-in-plan). Reuses the Phase 1 diff engine,
  scoped/annotated by plan membership.
- **Phase 4 — Queue warnings + dashboard:** scheduled check of
  `character_skillqueue` for empty / ending-soon queues → new alert; plus a
  read-only dashboard of members' skills, queue status, and plan progress.
- **Configurable-per-group filtering (preferred end-state):** because the diff
  engine emits raw completions and filtering happens at dispatch, a per-group
  filter (by plan / skill-list / member / role / corp) layers on without
  reworking the core.
