# DEVNOTES — fside/seat-skill-notifications

Developer reference for Tasks 2–8. Contains resolved dependency versions and
verbatim signature findings from the locally-installed vendor source.

---

## Resolved Dependency Versions

Resolved by `composer install --ignore-platform-reqs` (devshell: PHP 8.2.31,
Composer 2.10.1). The `--ignore-platform-reqs` flag is needed because
`eveseat/eveapi ^5.0` declares `ext-redis *` but the nix devshell only installs
pdo_sqlite, sqlite3, and bcmath. This is fine for development/testing (redis
is not exercised in unit tests).

| Package                | Resolved Version |
|------------------------|-----------------|
| eveseat/eveapi         | 5.0.35          |
| eveseat/notifications  | 5.0.17          |
| eveseat/services       | 5.1.0           |
| eveseat/web            | 5.0.35          |
| laravel/framework      | 10.x-dev (3ff39b7) |
| orchestra/testbench    | v8.37.0         |
| orchestra/testbench-core | v8.43.1       |
| phpunit/phpunit        | 10.5.63         |

**Constraint alignment:** `eveseat/eveapi ^5.0` resolved to Laravel 10
(framework 10.x-dev). `orchestra/testbench ^8.0` → testbench 8.x targets
Laravel 10. The brief's original `^8.0` constraint was correct — no adjustment
needed.

---

## Signature 1: DiscordEmbed Builder Methods

File: `vendor/eveseat/notifications/src/Services/Discord/Messages/DiscordEmbed.php`

```php
// Set the title (and optional clickable URL)
public function title(string $title, ?string $url = null): DiscordEmbed

// Set body text
public function description(string $description): DiscordEmbed

// Set timestamp (accepts DateInterval|DateTimeInterface|int|string)
public function timestamp(\DateInterval|\DateTimeInterface|int|string $timestamp): DiscordEmbed

// Set embed color (int, e.g. 0x00FF00)
public function color(int $color): DiscordEmbed

// Set footer text and optional icon URL
public function footer(string $text, ?string $icon = null): DiscordEmbed

// Set image URL
public function image(string $url): DiscordEmbed

// Set thumbnail URL
public function thumb(string $url): DiscordEmbed

// Set author block
public function author(string $name, ?string $icon = null, ?string $link = null): DiscordEmbed

// Add a single field — two forms:
//   Simple: ->field('Title', 'value') stores as $fields['Title'] = 'value'
//   Builder: ->field(function(DiscordEmbedField $f) { ... }) pushes a DiscordEmbedField
//   NOTE: there is NO $inline bool param — use the DiscordEmbedField object form if
//   you need inline control.
public function field($title, $value = ''): DiscordEmbed

// Replace all fields at once
public function fields(array $fields): DiscordEmbed
```

**Key deviation from plan assumptions:**
- `field()` does NOT accept `(name, value, inline)`. It accepts either
  `(string $title, string $value)` for a simple key/value, or a `Closure`
  that receives a `DiscordEmbedField` object. To set `inline`, use the closure
  form and set `$f->inline = true`.
- `timestamp()` takes a union type (no dedicated `DateTimeInterface`-only path
  in the public API); strings are special-cased via `carbon($timestamp)`.

---

## Signature 2: `dispatchNotifications` — how core builds `$groups` and dispatches

### The dispatcher (trait)

File: `vendor/eveseat/notifications/src/Traits/NotificationDispatchTool.php`

```php
// Signature:
public function dispatchNotifications($alert_type, $groups, $notification_creation_callback)
```

- `$alert_type` — string key matching a top-level key in `config('notifications.alerts')`,
  e.g. `'inactive_member'`, `'skill_trained'` (our key).
- `$groups` — an Eloquent Collection of `NotificationGroup` models, each
  eager-loaded with `integrations` and `mentions` relations.
- `$notification_creation_callback` — `function($notificationClass)` — receives
  the handler FQCN from config and must return a new notification instance.

The dispatcher reads `config('notifications.alerts.<alert_type>.handlers')` to
find the channel → handler class map, then routes via
`Notification::route($channel, $route)->notify($notification)`.

### How core builds the `$groups` argument — canonical example

From `CorporationMemberTrackingObserver.php`:

```php
use Seat\Notifications\Models\NotificationGroup;
use Seat\Notifications\Traits\NotificationDispatchTool;

// In the observer, $groups is built via:
// NOTE: eager-load 'integrations' and 'mentions' too — mapGroups() (called by
// dispatchNotifications) accesses $group->integrations and $group->mentions per
// group, so omitting them causes N+1 queries. (CharacterNotificationObserver /
// ContractDetailObserver use this fuller with() set.)
$groups = NotificationGroup::with('alerts', 'affiliations', 'integrations', 'mentions')
    ->whereHas('alerts', function ($query) {
        $query->where('alert', 'fside_skill_completed');  // <-- our alert key
    })->whereHas('affiliations', function ($query) use ($member) {
        $query->where('affiliation_id', $member->character_id);
        $query->orWhere('affiliation_id', $member->corporation_id);
    })->get();

$this->dispatchNotifications('inactive_member', $groups, function ($notificationClass) use ($member) {
    return new $notificationClass($member);
});
```

**For our plugin** we query for groups subscribed to our alert key
(`'fside_skill_completed'`) and affiliated with the character or their corporation ID.
(Task 6's `handle()` therefore needs the character's `corporation_id` as well as
the name — resolve it alongside the name in `resolveCharacter`.)

### NotificationGroup FQCN

```
Seat\Notifications\Models\NotificationGroup
```

Groups link to an alert key via the `alerts` relation → `GroupAlert` model,
column `alert` (string). Filter: `->where('alert', '<our_alert_key>')`.

### Config format (notifications.alerts)

```php
// vendor/eveseat/notifications/src/Config/notifications.alerts.php
'created_user' => [
    'label' => 'notifications::alerts.created_user',
    'handlers' => [
        'mail'    => \Seat\Notifications\Notifications\Seat\Mail\CreatedUser::class,
        'discord' => \Seat\Notifications\Notifications\Seat\Discord\CreatedUser::class,
        'slack'   => \Seat\Notifications\Notifications\Seat\Slack\CreatedUser::class,
    ],
],
```

Our plugin merges into this config via `mergeConfigFrom()` in the service
provider with key `'skill_trained'` (or similar).

---

## Signature 3: `InvType` — table and name column

File: `vendor/eveseat/eveapi/src/Models/Sde/InvType.php`

```php
protected $table = 'invTypes';           // table name (camelCase, SDE convention)
protected $primaryKey = 'typeID';        // primary key column
// Name column:
// @property typeName  (OA\Property: 'typeName', type: 'string')
```

**Key findings:**
- Table: `invTypes` (not snake_case)
- Primary key: `typeID` (not `type_id`)
- Name column: `typeName` (not `type_name`, not `name`)
- The `CharacterSkillQueue` links to `InvType` via:
  ```php
  return $this->belongsTo(InvType::class, 'skill_id', 'typeID');
  ```
- To get skill name from a queue entry: `$queueEntry->type->typeName`

---

## Signature 4: `CharacterInfo` — character name and corporation name

File: `vendor/eveseat/eveapi/src/Models/Character/CharacterInfo.php`
Migration: `vendor/eveseat/eveapi/src/database/migrations/2018_01_03_171826_create_character_infos_table.php`

### Character name

The `character_infos` table has a `name` column directly:

```sql
$table->string('name');        -- character name, stored directly on the row
$table->bigInteger('corporation_id');  -- FK to corporation
```

Access: `$characterInfo->name`

### Corporation name

`CharacterInfo` does NOT have a direct `corporation` relation that returns a
corporation name string. The corporation name comes via `CharacterAffiliation`
(a separate model) using `UniverseName` as intermediary:

```php
// On CharacterAffiliation (vendor/.../Character/CharacterAffiliation.php):
public function corporation()
{
    return $this->hasOne(UniverseName::class, 'entity_id', 'corporation_id')
        ->withDefault([
            'name' => trans('web::seat.unknown'),
            'category' => 'corporation',
        ]);
}

public function corporationInfo()
{
    return $this->hasOne(CorporationInfo::class, 'corporation_id', 'corporation_id');
}
```

`UniverseName` model: table has `entity_id` (int) and `name` (string) columns.

**Pattern for getting corporation name in our plugin:**

```php
// Option A — via CharacterInfo's affiliation relation:
$characterInfo->affiliation->corporation->name
// (affiliation is a hasOne(CharacterAffiliation::class) with withDefault())

// Option B — via CorporationInfo (has a 'name' column directly):
$characterInfo->affiliation->corporationInfo->name

// Option C — direct lookup from corporation_id:
\Seat\Eveapi\Models\Universe\UniverseName::where('entity_id', $characterInfo->corporation_id)->value('name')
```

`CorporationInfo` has `name` as a direct column (OA annotation confirms:
`@property name description='The name of the corporation'`).

---

## Bonus: `AbstractScheduleSeeder` — Task 7 reference

File: `vendor/eveseat/services/src/Seeding/AbstractScheduleSeeder.php`

**FQCN:** `Seat\Services\Seeding\AbstractScheduleSeeder`

**Contract (both methods are abstract):**

```php
abstract class AbstractScheduleSeeder extends \Illuminate\Database\Seeder
{
    /**
     * Returns an array of schedules to seed on stack boot.
     * Each element is an associative array matching the `schedules` table columns.
     * @return array
     */
    abstract public function getSchedules(): array;

    /**
     * Returns a list of command strings to remove from the schedule (deprecations).
     * @return array
     */
    abstract public function getDeprecatedSchedules(): array;

    public function run(): void { /* inserts missing, deletes deprecated */ }
}
```

`run()` inserts rows from `getSchedules()` into the `schedules` DB table
(one row per job; checks `command` column for duplicates before inserting),
then deletes any rows whose `command` matches entries from `getDeprecatedSchedules()`.

A typical `getSchedules()` return value (from other SeAT plugins) looks like:

```php
return [
    [
        'command'    => 'skillnotify:scan',
        'expression' => '*/10 * * * *',
        'allow_overlap' => false,
        'allow_maintenance' => false,
        'ping_before' => null,
        'ping_after'  => null,
    ],
];
```

---

## Notes on platform requirements

`eveseat/eveapi ^5.0` requires `ext-redis` (for queue/cache). The nix devshell
does NOT include the Redis PHP extension — it only has pdo_sqlite, sqlite3, bcmath.

**Resolution:** use `composer install --ignore-platform-reqs` for local dev/testing.
This is safe because:
- The redis extension is never called in unit tests (Testbench uses sqlite in-memory).
- Production runs on the SeAT v5 docker stack which provides the extension.

The `composer.json` does NOT need to be changed; the constraint is valid. Simply
always use `--ignore-platform-reqs` in the devshell.
