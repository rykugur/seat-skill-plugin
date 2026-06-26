# SeAT Hangar Tracker (prototype)

A SeAT plugin that tracks corp hangar (asset) deposits/withdrawals and notifies
Discord, plus skill-queue and skill-plan-milestone notifications for corp
members.

## Architecture

- Built as a real SeAT plugin (Laravel service provider via `AbstractSeatPlugin`),
  not a standalone app — installed with `composer require` into your SeAT
  instance and registered in its job scheduler.
- Reads SeAT's own already-synced data (`corporation_assets`, `character_skillqueue`,
  `character_skills`) rather than calling ESI directly. SeAT already owns the
  ESI OAuth/token-refresh/rate-limit lifecycle; duplicating that inside a
  plugin is extra surface area for very little benefit, since the asset
  snapshot SeAT stores already carries item-level and division-level detail
  (`type_id`, `item_id`, `quantity`, `location_flag`).
- "Deposit/withdrawal" detection works by diffing SeAT's current asset
  snapshot against our own stored prior snapshot (`hangartracker_asset_snapshots`)
  per corp hangar division (`location_flag` values `CorpSAG1`..`CorpSAG7`),
  since ESI/SeAT only exposes a current-state snapshot, not a movement log.
- Discord delivery goes through a small `DiscordNotifier` service posting to
  webhook URLs stored encrypted in the database, configurable per-webhook by
  notification category (hangar movements / skill queue warnings / skill plan
  milestones), each independently toggleable so you can route different
  alerts to different channels.
- All plugin tables are namespaced `hangartracker_*` and avoid foreign keys
  into SeAT core tables, per SeAT's own package-development guidance to
  avoid coupling plugin migrations to core migration ordering.

## What's confirmed vs inferred

I verified the plugin skeleton conventions (service provider extending
`AbstractSeatPlugin`, `loadMigrationsFrom`, schedule registration via
`AbstractScheduleSeeder`, route naming `seat<plugin>::route.name`, avoiding
core-table foreign keys, extending `ExtensibleModel`) directly against SeAT's
package development documentation and development tips page. Those should be
solid.

I was **not** able to verify the exact class/column names inside
`eveseat/eveapi` and `eveseat/services` against live source in this session
(no PHP available in my sandbox, and I didn't have direct repo browsing
access to the specific files). Several names in this skeleton are
educated-guess placeholders based on the public ESI schema and common SeAT
naming conventions, not confirmed reads of the real code. **Before running
any of this against a real SeAT instance, check these against your actual
installed version** (`vendor/eveseat/eveapi/src/Models/...`):

- `Seat\Eveapi\Models\Assets\CorporationAsset` — model/table name and whether
  `location_flag` is stored as a string enum (`CorpSAG1`) or already mapped
  to an integer division.
- `Seat\Eveapi\Models\Character\CharacterSkillQueue` — model name and the
  `finish_date` column name for in-progress skill queue entries.
- `Seat\Eveapi\Models\Character\CharacterSkill` — model name and whether the
  trained-level column is `trained_skill_level` or something else.
- `Seat\Eveapi\Models\Sde\InvType` — the SDE-backed type-name lookup model
  and whether the name column is `typeName` or `type_name`.
- `AbstractSeatPlugin`'s actual abstract method names (`getName`,
  `getPackageRepositoryUrl`, `getPackageName` here are inferred from the
  general pattern, not confirmed against the real abstract class signature).
- The Blade layout `web::layouts.grids.12` used in the three views — check
  `eveseat/web`'s actual grid view names for your installed version.

None of this is exotic to fix — it's a few `grep -r` passes through
`vendor/eveseat/eveapi/src/Models` and `vendor/eveseat/services/src` to swap
in the real names — but it does mean this skeleton will not run unmodified.
Treat it as a structurally-correct first draft, not a tested package.

## Setup (once verified against your SeAT version)

```bash
composer require yourcorp/seat-hangar-tracker:@dev
php artisan migrate
php artisan db:seed --class=Seat\\Services\\Database\\Seeders\\PluginDatabaseSeeder
php artisan config:clear && php artisan view:clear
```

Then visit Settings → Hangar Tracker (or the sidebar entry) to add a Discord
webhook, and grant the `hangartracker.view` / `hangartracker.settings`
permissions via SeAT's Access Management to the roles that should see it.

## What's intentionally out of scope for this prototype

- No UI yet for creating/editing skill plans (only assigning + viewing is
  wired up) — plans currently need to be seeded via tinker or a future admin
  form.
- No backfill command for the first asset-diff run; the first run after
  install will treat all current hangar contents as "deposits" since there's
  no prior snapshot to diff against. You may want to seed
  `hangartracker_asset_snapshots` from the current `corporation_assets` state
  once, silently, before turning on Discord notifications.
- No rate limiting on Discord webhook delivery beyond Discord's own; if a lot
  of items move at once you may hit Discord's per-webhook rate limit. Worth
  batching multiple movements from one diff run into a single embed if this
  becomes noisy in practice.
