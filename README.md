# FSIDE Skill Notifications

A [SeAT](https://eveseat.github.io/docs/) v5 plugin that posts a native Discord
notification whenever a corporation member finishes training a skill
(e.g. *"Korgoroth completed Caldari Battleship V"*).

It reads SeAT's already-synced skill data and delivers through SeAT's built-in
notification framework, so alerts look and route exactly like SeAT's own
notifications (the same embed style as the Contract Monitor).

> **Status:** Phase 1 (skill-completion notifications). Skill plans, milestone
> alerts, skill-queue warnings, a dashboard, and per-group filtering are planned
> later phases — see `docs/superpowers/specs/`.

## How it works

- A scheduled command, `skillnotify:scan`, reads SeAT's `character_skills` and
  compares each character's `trained_skill_level` against a plugin-owned
  snapshot table (`skillnotify_skill_snapshots`). Any level increase is a
  completion.
- Completions are dispatched as a `SkillCompleted` Discord notification to every
  SeAT notification group that has the **Skill Completed** alert enabled.
- **First run is silent.** The first time a character is seen, its current
  skills are recorded as a baseline with no notifications — so installing the
  plugin (or a member joining later) never floods you with their whole history.
- It never calls ESI directly; SeAT already owns the ESI/token lifecycle.

## Requirements

- SeAT **v5** (the plugin targets `eveseat/services`, `eveseat/notifications`,
  `eveseat/eveapi` `^5.0`)
- **MariaDB** (SeAT's only officially supported database)
- PHP 8.1+

## Installation

Install into your SeAT instance with Composer, then migrate and clear caches.

For local/path-based installs, register this directory as a path repository:

```bash
composer config repositories.skillnotify path /path/to/seat-skill-plugin
composer require fside/seat-skill-notifications:@dev
php artisan migrate
php artisan config:clear && php artisan view:clear
```

(When running the official SeAT Docker stack, prefix the above with
`docker compose exec seat-web …`.)

### Seed a baseline before enabling Discord (recommended)

To be certain no historical skills are announced when you first turn on a
channel, baseline every character once before enabling any notification group:

```bash
php artisan skillnotify:seed
```

`skillnotify:scan` also baselines new characters silently on their first run, so
this step is belt-and-suspenders.

## Configuring Discord notifications

This plugin registers a notification alert; routing is done the SeAT-native way:

1. In SeAT, go to **Settings → Notifications** and create (or pick) a
   notification group.
2. Add a **Discord** integration (webhook) to that group.
3. Enable the **Skill Completed** alert on the group.

Every member's skill completions will be delivered to that group's Discord
channel. (Add multiple groups to route to different channels.)

## Commands

| Command | Purpose |
| --- | --- |
| `skillnotify:scan` | Detect completions since the last run and notify enabled groups. Registered to run every 15 minutes by default. |
| `skillnotify:seed` | Baseline all characters' snapshots without sending notifications (install-time backfill). |

The scan schedule (`*/15 * * * *`) is seeded as a default into SeAT's schedule
table and can be changed from SeAT's schedule-management UI without code
changes.

## Development

Nix is an optional convenience (it is **not** required — production runs on
Ubuntu with the SeAT stack's PHP/Composer). A flake devshell pins PHP 8.2 +
Composer with the extensions needed for the test suite:

```bash
nix develop            # or `direnv allow` once, then auto-enter
composer install
vendor/bin/phpunit     # 16 tests, all green
```

Without Nix, any PHP 8.1+ with Composer works: `composer install && vendor/bin/phpunit`.

Tests run on in-memory SQLite via Orchestra Testbench; MariaDB remains the
production target. `DEVNOTES.md` records the verified SeAT v5 class/column
signatures the plugin depends on.

A sample SeAT v5 Docker stack for local testing lives in `docker/`
(fill in `docker/.env` with your DB passwords, `APP_KEY`, and EVE app
credentials before bringing it up).

The dev compose file persists `/var/www/seat` in a named `seat-app` volume so
Composer-installed packages survive container recreation. A normal
`docker compose stop` / `docker compose start` keeps the same containers too.
Running `docker compose down -v` intentionally deletes the named volumes, so
after that you must reinstall this package with the Composer commands above.

## Project layout

```
src/
  SkillNotificationsServiceProvider.php   plugin registration
  Console/Scan.php, Seed.php              the two artisan commands
  Services/SkillDiff.php                  pure completion-detection
  Services/CompletionHandler.php          detection→dispatch seam (interface)
  Services/NotificationCompletionHandler.php  dispatch to notification groups
  Services/SnapshotWriter.php             snapshot upsert
  Models/SkillSnapshot.php                skillnotify_skill_snapshots
  Notifications/Discord/SkillCompleted.php  the Discord embed
  Config/notifications.alerts.php         registers the fside_skill_completed alert
  Database/Seeders/ScheduleSeeder.php     default 15-minute schedule
docs/superpowers/                         design spec + implementation plan
```

## License

GPL-2.0-only
