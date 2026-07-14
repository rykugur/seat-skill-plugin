# Architecture

The plugin is a standard Composer/Laravel package for SeAT v5.

## Package registration

`src/SkillNotificationsServiceProvider.php` extends
`Seat\Services\AbstractSeatPlugin` and registers:

- migrations from `src/Database/migrations`
- translations from `src/resources/lang`
- the `notifications.alerts` config entry
- the default schedule seeder
- artisan commands `skillnotify:scan` and `skillnotify:seed`
- the default `CompletionHandler` binding

Composer autoload maps `Fside\SkillNotifications\` to `src/`, and Laravel
package discovery loads the service provider.

## Data tables

`skillnotify_skill_snapshots` stores the last accounted trained level for each
character and skill.

`skillnotify_skill_completions` stores detected level increases with
`from_level`, `to_level`, and nullable `notified_at`. It has a uniqueness guard
on character, skill, and target level to avoid duplicate completion records.

The plugin intentionally does not add foreign keys into SeAT core tables.

## Core services

`SkillDiff` is a pure detector:

- input: snapshot levels and current levels as `skill_id => trained_skill_level`
- output: `Completion` value objects for increases only
- decreases and unchanged levels do not emit completions

`SnapshotWriter` upserts snapshot rows after a character has been scanned.

`NotificationCompletionHandler` resolves character/corporation metadata, finds
matching SeAT notification groups, resolves skill names through `InvType`, and
dispatches channel-specific notification handlers.

## Command flow

`skillnotify:scan` scans distinct characters in `character_skills`.

For each character:

1. Load current skill levels.
2. Load plugin snapshots.
3. If no snapshot exists for the character, baseline silently.
4. Diff snapshot against current levels.
5. Record completion rows.
6. Update snapshots to current levels.

After character transactions complete, the command dispatches pending completion
rows. This keeps detection durable while avoiding notification dispatch inside
the snapshot transaction.

