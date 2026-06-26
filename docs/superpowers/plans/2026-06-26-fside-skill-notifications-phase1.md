# FSIDE Skill Notifications — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a SeAT v5 plugin that posts a native Discord embed whenever a corp member finishes training a skill level.

**Architecture:** A scheduled artisan command (`skillnotify:scan`) reads SeAT's already-synced `character_skills`, diffs each character's `trained_skill_level` against a plugin-owned snapshot table, and hands any level increases to a `CompletionHandler`. The default handler renders a `Seat\Notifications` Discord notification and dispatches it through SeAT's notification-group framework. Detection (a pure `SkillDiff` function) is fully decoupled from dispatch behind the `CompletionHandler` interface, so notifications/filtering/batching can change without touching the engine.

**Tech Stack:** PHP 8.1+, Laravel (via SeAT v5), `eveseat/services` (`AbstractSeatPlugin`), `eveseat/notifications` (Discord notifications), `eveseat/eveapi` (models), MariaDB, PHPUnit, Docker.

## Global Constraints

- **Target platform:** SeAT v5, running on **MariaDB only** (SeAT v5 supports no other DB). The plugin uses SeAT's default Laravel connection and never opens its own.
- **Package name:** `fside/seat-skill-notifications`. **PHP namespace:** `Fside\SkillNotifications\`.
- **Tables:** namespaced `skillnotify_*`. **No foreign keys into SeAT core tables.** Migrations use portable Laravel schema-builder (no MariaDB-only raw SQL).
- **Data source:** read SeAT's synced tables only — never call ESI directly.
- **Notifications:** use SeAT's built-in framework (alert registered in `notifications.alerts.php`, formatter extends `Seat\Notifications\Notifications\AbstractDiscordNotification`, dispatch via `Seat\Notifications\Traits\NotificationDispatchTool`). No custom webhooks.
- **Discipline:** TDD (test first), DRY, YAGNI, commit after every passing step.
- **Default scan cadence:** every 15 min; admins can change it later via SeAT's schedule UI (it's only a seeded default).

### Verified SeAT signatures (confirmed against `master` source)

- `Seat\Services\AbstractSeatPlugin` abstract methods: `getName(): string`, `getPackageRepositoryUrl(): string`, `getPackagistPackageName(): string`, `getPackagistVendorName(): string`. Helpers: `registerPermissions(string $path, string $scope='global')`, `registerDatabaseSeeders(string|array $classes)`, `registerSdeTables($tables)`.
- `Seat\Notifications\Notifications\AbstractDiscordNotification` (namespace `Seat\Notifications\Notifications`): `via()` returns `['discord']`; subclass implements `abstract protected function populateMessage(DiscordMessage $message, $notifiable)`.
- `Seat\Notifications\Services\Discord\Messages\DiscordMessage` builder: `from(string $username, ?string $icon=null)`, `to(string $channel)`, `content(string)`, `embed(Closure $callback)`, plus `info()/success()/warning()/error()`.
- `Seat\Notifications\Traits\NotificationDispatchTool`: `dispatchNotifications($alert_type, $groups, $notification_creation_callback)` and `mapGroups($groups)`.
- `Seat\Eveapi\Models\Character\CharacterSkill` → table `character_skills`, columns `character_id`, `skill_id`, `trained_skill_level`, `active_skill_level`, `skillpoints_in_skill`.

### UNVERIFIED — confirm in Task 1 against the running v5 `vendor/` (record results in `DEVNOTES.md`)

These could not be confirmed from here; Task 1 greps them and later tasks adapt if they differ:

1. **`DiscordEmbed` builder method names** — the plan assumes `title()`, `field(name, value, inline)`, `timestamp()`, `color()`. Confirm in `vendor/eveseat/notifications/src/Services/Discord/Messages/DiscordEmbed.php`.
2. **How to fetch notification groups subscribed to an alert** — the `$groups` argument to `dispatchNotifications`. Find how core observers build it (grep `dispatchNotifications` usages in `vendor/eveseat/notifications/src` and `vendor/eveseat/eveapi/src`). The plan isolates this in `NotificationCompletionHandler::groupsForAlert()`.
3. **`Seat\Eveapi\Models\Sde\InvType`** name column — `typeName` vs `type_name`. Grep `vendor/eveseat/eveapi/src/Models/Sde/InvType.php`.
4. **`Seat\Eveapi\Models\Character\CharacterInfo`** — confirm it exposes character `name` and a relation/column to resolve the corporation name. Grep `vendor/eveseat/eveapi/src/Models/Character/CharacterInfo.php`.

---

## File Structure

```
composer.json                                         package definition
docker/docker-compose.yml                             SeAT v5 dev stack
docker/.env                                           dev stack config (gitignored)
DEVNOTES.md                                           recorded vendor-signature findings (Task 1)
phpunit.xml                                           test config
src/
  SkillNotificationsServiceProvider.php               extends AbstractSeatPlugin
  Config/notifications.alerts.php                     registers fside_skill_completed
  Services/
    Completion.php                                    value object {skillId, fromLevel, toLevel}
    SkillDiff.php                                      pure diff(snapshot, current): Completion[]
    CompletionHandler.php                             interface handle(int $characterId, array $completions)
    NotificationCompletionHandler.php                 default handler -> dispatches Discord notification
  Models/SkillSnapshot.php                            Eloquent over skillnotify_skill_snapshots
  Console/
    Scan.php                                          artisan skillnotify:scan
    Seed.php                                          artisan skillnotify:seed (backfill)
  Database/
    Seeders/ScheduleSeeder.php                        AbstractScheduleSeeder default cadence
    migrations/2026_06_26_000000_create_skillnotify_skill_snapshots.php
  Notifications/Discord/SkillCompleted.php            extends AbstractDiscordNotification
  resources/lang/en/alerts.php                        translation tokens
tests/
  Unit/SkillDiffTest.php
  Feature/ScanCommandTest.php
  Feature/SkillCompletedNotificationTest.php
  Feature/ScheduleSeederTest.php
```

---

## Task 1: Dev environment, package scaffold, and signature verification

**Files:**
- Create: `docker/docker-compose.yml`, `docker/.env`, `composer.json`, `.gitignore`, `DEVNOTES.md`
- Create: `src/SkillNotificationsServiceProvider.php`

**Interfaces:**
- Produces: a loadable SeAT plugin `Fside\SkillNotifications\SkillNotificationsServiceProvider`; a running SeAT v5 with `vendor/eveseat/*` available to grep.

- [ ] **Step 1: Get the official SeAT v5 docker stack**

```bash
mkdir -p docker
curl -fsSL https://raw.githubusercontent.com/eveseat/docker/master/docker-compose.yml -o docker/docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/eveseat/docker/master/.env.example -o docker/.env
```

Edit `docker/.env` and set at minimum: `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`, `DB_PASSWORD` (same as `MYSQL_PASSWORD`), `APP_KEY` (generate with `echo base64:$(openssl rand -base64 32)`), and your EVE app `EVE_CLIENT_ID` / `EVE_CLIENT_SECRET` / `EVE_CLIENT_REFRESH_SCOPES` (read scopes incl. `esi-skills.read_skills.v1`, `esi-skills.read_skillqueue.v1`). Confirm the DB image is MariaDB.

- [ ] **Step 2: Create `.gitignore`**

```gitignore
/vendor/
/docker/.env
composer.lock
.phpunit.result.cache
```

- [ ] **Step 3: Create `composer.json`**

```json
{
    "name": "fside/seat-skill-notifications",
    "description": "SeAT plugin: Discord notifications when corp members finish training skills.",
    "type": "library",
    "license": "GPL-2.0-only",
    "require": {
        "php": ">=8.1",
        "eveseat/services": "^5.0",
        "eveseat/notifications": "^5.0",
        "eveseat/eveapi": "^5.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "orchestra/testbench": "^8.0"
    },
    "autoload": {
        "psr-4": { "Fside\\SkillNotifications\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Fside\\SkillNotifications\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Fside\\SkillNotifications\\SkillNotificationsServiceProvider"
            ]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

> Confirm the exact `eveseat/*` version constraint against the running v5 image (`docker compose exec front composer show 'eveseat/*' | grep versions`); adjust `^5.0` if the image pins a different major/minor.

- [ ] **Step 4: Create the service provider**

`src/SkillNotificationsServiceProvider.php`:

```php
<?php

namespace Fside\SkillNotifications;

use Seat\Services\AbstractSeatPlugin;

class SkillNotificationsServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'skillnotify');

        // Register the notification alert into core config.
        $this->mergeConfigFrom(__DIR__ . '/Config/notifications.alerts.php', 'notifications.alerts');

        // Register the scheduled scan default cadence.
        $this->registerDatabaseSeeders(Database\Seeders\ScheduleSeeder::class);
    }

    public function register()
    {
        $this->commands([
            Console\Scan::class,
            Console\Seed::class,
        ]);

        // Default completion handler -> dispatches Discord notifications.
        $this->app->bind(
            Services\CompletionHandler::class,
            Services\NotificationCompletionHandler::class
        );
    }

    public function getName(): string
    {
        return 'Skill Notifications';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/fside/seat-skill-notifications';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-skill-notifications';
    }

    public function getPackagistVendorName(): string
    {
        return 'fside';
    }
}
```

> This references classes created in later tasks (`ScheduleSeeder`, `Scan`, `Seed`, `CompletionHandler`, `NotificationCompletionHandler`, the config and lang files). It will not boot cleanly until those exist; that is expected. Do not run SeAT against this provider until Task 8. Tasks 2–8 are developed and tested via Testbench (Step 6) which does not require the full provider to boot.

- [ ] **Step 5: Bring the stack up and verify vendor source is present**

```bash
docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml exec front php artisan --version
docker compose -f docker/docker-compose.yml exec front ls vendor/eveseat
```
Expected: an artisan version string and a directory listing including `services`, `notifications`, `eveapi`.

- [ ] **Step 6: Verify the UNVERIFIED signatures and record them**

Run each grep inside the container and write findings into `DEVNOTES.md`:

```bash
cd # working dir of the SeAT front container, then:
grep -n "function " vendor/eveseat/notifications/src/Services/Discord/Messages/DiscordEmbed.php
grep -rn "dispatchNotifications(" vendor/eveseat/notifications/src vendor/eveseat/eveapi/src
grep -n "typeName\|type_name\|protected \$table" vendor/eveseat/eveapi/src/Models/Sde/InvType.php
grep -n "function \|protected \$table\|name" vendor/eveseat/eveapi/src/Models/Character/CharacterInfo.php
```

Create `DEVNOTES.md` capturing, verbatim: the real `DiscordEmbed` builder method names/signatures; one real example of how core builds the `$groups` argument and calls `dispatchNotifications`; the `InvType` name column; and how to resolve a character's name + corporation name. **If any differ from this plan's assumptions, note the correct form** — Tasks 5/6 reference `DEVNOTES.md`.

- [ ] **Step 7: Install dev dependencies for the test harness**

```bash
composer install
```
Expected: `vendor/bin/phpunit` exists.

- [ ] **Step 8: Commit**

```bash
git add composer.json .gitignore docker/docker-compose.yml src/SkillNotificationsServiceProvider.php DEVNOTES.md
git commit -m "chore: scaffold package, docker dev stack, and vendor signature notes"
```

---

## Task 2: SkillDiff pure detection function

**Files:**
- Create: `src/Services/Completion.php`, `src/Services/SkillDiff.php`
- Create: `tests/Unit/SkillDiffTest.php`, `phpunit.xml`, `tests/TestCase.php`

**Interfaces:**
- Produces: `Fside\SkillNotifications\Services\Completion` (readonly `int $skillId`, `int $fromLevel`, `int $toLevel`); `Fside\SkillNotifications\Services\SkillDiff::diff(array $snapshot, array $current): Completion[]` where both arrays are `skill_id => trained_skill_level`.

- [ ] **Step 1: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Create the Testbench base test case**

`tests/TestCase.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Fside\SkillNotifications\SkillNotificationsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SkillNotificationsServiceProvider::class];
    }
}
```

- [ ] **Step 3: Write the failing test**

`tests/Unit/SkillDiffTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fside\SkillNotifications\Services\SkillDiff;
use Fside\SkillNotifications\Services\Completion;

class SkillDiffTest extends TestCase
{
    private SkillDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new SkillDiff();
    }

    public function test_newly_trained_skill_with_no_snapshot_is_a_completion_from_zero(): void
    {
        $result = $this->diff->diff([], [3340 => 3]);

        $this->assertCount(1, $result);
        $this->assertEquals(3340, $result[0]->skillId);
        $this->assertEquals(0, $result[0]->fromLevel);
        $this->assertEquals(3, $result[0]->toLevel);
    }

    public function test_level_increase_is_a_completion(): void
    {
        $result = $this->diff->diff([3340 => 4], [3340 => 5]);

        $this->assertCount(1, $result);
        $this->assertEquals(4, $result[0]->fromLevel);
        $this->assertEquals(5, $result[0]->toLevel);
    }

    public function test_multi_level_jump_is_a_single_completion(): void
    {
        $result = $this->diff->diff([3340 => 2], [3340 => 5]);

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]->fromLevel);
        $this->assertEquals(5, $result[0]->toLevel);
    }

    public function test_unchanged_skill_is_not_a_completion(): void
    {
        $this->assertSame([], $this->diff->diff([3340 => 5], [3340 => 5]));
    }

    public function test_level_decrease_is_not_a_completion(): void
    {
        $this->assertSame([], $this->diff->diff([3340 => 5], [3340 => 4]));
    }

    public function test_multiple_skills_yield_only_the_increased_ones(): void
    {
        $result = $this->diff->diff(
            [3340 => 5, 3413 => 1, 3416 => 3],
            [3340 => 5, 3413 => 2, 3416 => 3]
        );

        $this->assertCount(1, $result);
        $this->assertEquals(3413, $result[0]->skillId);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: FAIL — `Class "Fside\SkillNotifications\Services\SkillDiff" not found`.

- [ ] **Step 5: Create the `Completion` value object**

`src/Services/Completion.php`:

```php
<?php

namespace Fside\SkillNotifications\Services;

class Completion
{
    public function __construct(
        public readonly int $skillId,
        public readonly int $fromLevel,
        public readonly int $toLevel,
    ) {}
}
```

- [ ] **Step 6: Implement `SkillDiff`**

`src/Services/SkillDiff.php`:

```php
<?php

namespace Fside\SkillNotifications\Services;

class SkillDiff
{
    /**
     * @param array<int,int> $snapshot skill_id => trained_skill_level (last accounted)
     * @param array<int,int> $current  skill_id => trained_skill_level (current truth)
     * @return Completion[]
     */
    public function diff(array $snapshot, array $current): array
    {
        $completions = [];

        foreach ($current as $skillId => $level) {
            $previous = $snapshot[$skillId] ?? 0;
            if ($level > $previous) {
                $completions[] = new Completion((int) $skillId, (int) $previous, (int) $level);
            }
        }

        return $completions;
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: PASS (6 tests).

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml tests/TestCase.php tests/Unit/SkillDiffTest.php src/Services/Completion.php src/Services/SkillDiff.php
git commit -m "feat: add pure SkillDiff completion-detection function"
```

---

## Task 3: Snapshot table and model

**Files:**
- Create: `src/Database/migrations/2026_06_26_000000_create_skillnotify_skill_snapshots.php`
- Create: `src/Models/SkillSnapshot.php`
- Create: `tests/Feature/SkillSnapshotTest.php`

**Interfaces:**
- Produces: table `skillnotify_skill_snapshots` (composite PK `character_id`,`skill_id`; `trained_skill_level`); model `Fside\SkillNotifications\Models\SkillSnapshot` supporting `SkillSnapshot::upsert($rows, ['character_id','skill_id'], ['trained_skill_level'])`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SkillSnapshotTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Models\SkillSnapshot;

class SkillSnapshotTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_snapshot_rows_can_be_upserted_by_character_and_skill(): void
    {
        SkillSnapshot::upsert(
            [['character_id' => 90000001, 'skill_id' => 3340, 'trained_skill_level' => 3]],
            ['character_id', 'skill_id'],
            ['trained_skill_level']
        );
        SkillSnapshot::upsert(
            [['character_id' => 90000001, 'skill_id' => 3340, 'trained_skill_level' => 4]],
            ['character_id', 'skill_id'],
            ['trained_skill_level']
        );

        $this->assertEquals(1, SkillSnapshot::count());
        $this->assertEquals(
            4,
            SkillSnapshot::where('character_id', 90000001)->where('skill_id', 3340)->value('trained_skill_level')
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Feature --filter SkillSnapshotTest`
Expected: FAIL — model/table missing.

- [ ] **Step 3: Write the migration**

`src/Database/migrations/2026_06_26_000000_create_skillnotify_skill_snapshots.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skillnotify_skill_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id');
            $table->integer('skill_id');
            $table->unsignedTinyInteger('trained_skill_level');
            $table->timestamp('updated_at')->nullable();

            $table->primary(['character_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skillnotify_skill_snapshots');
    }
};
```

- [ ] **Step 4: Write the model**

`src/Models/SkillSnapshot.php`:

```php
<?php

namespace Fside\SkillNotifications\Models;

use Illuminate\Database\Eloquent\Model;

class SkillSnapshot extends Model
{
    protected $table = 'skillnotify_skill_snapshots';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['character_id', 'skill_id', 'trained_skill_level'];

    protected $casts = [
        'character_id' => 'integer',
        'skill_id' => 'integer',
        'trained_skill_level' => 'integer',
    ];
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Feature --filter SkillSnapshotTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Database/migrations src/Models/SkillSnapshot.php tests/Feature/SkillSnapshotTest.php
git commit -m "feat: add skillnotify_skill_snapshots table and model"
```

---

## Task 4: Scan command — baseline + diff (handler-decoupled)

**Files:**
- Create: `src/Services/CompletionHandler.php`
- Create: `src/Console/Scan.php`
- Create: `tests/Feature/ScanCommandTest.php`

**Interfaces:**
- Consumes: `SkillDiff::diff()`, `SkillSnapshot` (Task 2/3), `Seat\Eveapi\Models\Character\CharacterSkill` (table `character_skills`, columns `character_id`,`skill_id`,`trained_skill_level`).
- Produces: interface `Fside\SkillNotifications\Services\CompletionHandler::handle(int $characterId, Completion[] $completions): void`; command signature `skillnotify:scan`; `Scan::handle(SkillDiff, CompletionHandler): int` returns total completions emitted.

- [ ] **Step 1: Write the `CompletionHandler` interface**

`src/Services/CompletionHandler.php`:

```php
<?php

namespace Fside\SkillNotifications\Services;

interface CompletionHandler
{
    /**
     * @param Completion[] $completions
     */
    public function handle(int $characterId, array $completions): void;
}
```

- [ ] **Step 2: Write the failing test**

The test binds a fake handler that records calls, seeds the core `character_skills` table directly, and asserts baseline-then-completion behaviour.

`tests/Feature/ScanCommandTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Services\CompletionHandler;
use Fside\SkillNotifications\Services\Completion;
use Fside\SkillNotifications\Models\SkillSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecordingHandler implements CompletionHandler
{
    /** @var array<int,Completion[]> */
    public array $calls = [];

    public function handle(int $characterId, array $completions): void
    {
        $this->calls[$characterId] = $completions;
    }
}

class ScanCommandTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private RecordingHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal stand-in for SeAT's character_skills so the test does not
        // require the full eveapi migration set.
        if (! Schema::hasTable('character_skills')) {
            Schema::create('character_skills', function ($table) {
                $table->unsignedBigInteger('character_id');
                $table->integer('skill_id');
                $table->unsignedTinyInteger('trained_skill_level');
                $table->unsignedBigInteger('skillpoints_in_skill')->default(0);
                $table->primary(['character_id', 'skill_id']);
            });
        }

        $this->handler = new RecordingHandler();
        $this->app->instance(CompletionHandler::class, $this->handler);
    }

    private function setSkill(int $charId, int $skillId, int $level): void
    {
        DB::table('character_skills')->updateOrInsert(
            ['character_id' => $charId, 'skill_id' => $skillId],
            ['trained_skill_level' => $level, 'skillpoints_in_skill' => 1000]
        );
    }

    public function test_first_run_baselines_silently(): void
    {
        $this->setSkill(90000001, 3340, 3);

        $this->artisan('skillnotify:scan')->assertExitCode(0);

        $this->assertSame([], $this->handler->calls, 'first run must emit no completions');
        $this->assertEquals(3, SkillSnapshot::where('character_id', 90000001)->where('skill_id', 3340)->value('trained_skill_level'));
    }

    public function test_level_increase_after_baseline_emits_one_completion(): void
    {
        $this->setSkill(90000001, 3340, 3);
        $this->artisan('skillnotify:scan'); // baseline

        $this->setSkill(90000001, 3340, 4); // member trains a level
        $this->handler->calls = [];
        $this->artisan('skillnotify:scan')->assertExitCode(0);

        $this->assertArrayHasKey(90000001, $this->handler->calls);
        $this->assertCount(1, $this->handler->calls[90000001]);
        $this->assertEquals(4, $this->handler->calls[90000001][0]->toLevel);
        $this->assertEquals(4, SkillSnapshot::where('character_id', 90000001)->where('skill_id', 3340)->value('trained_skill_level'));
    }

    public function test_rerun_with_no_change_emits_nothing(): void
    {
        $this->setSkill(90000001, 3340, 3);
        $this->artisan('skillnotify:scan'); // baseline
        $this->handler->calls = [];

        $this->artisan('skillnotify:scan')->assertExitCode(0);

        $this->assertSame([], $this->handler->calls);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Feature --filter ScanCommandTest`
Expected: FAIL — command `skillnotify:scan` not registered.

- [ ] **Step 4: Implement the `Scan` command**

`src/Console/Scan.php`:

```php
<?php

namespace Fside\SkillNotifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Fside\SkillNotifications\Models\SkillSnapshot;
use Fside\SkillNotifications\Services\SkillDiff;
use Fside\SkillNotifications\Services\CompletionHandler;

class Scan extends Command
{
    protected $signature = 'skillnotify:scan';

    protected $description = 'Detect skill-training completions and notify enabled channels.';

    public function handle(SkillDiff $diff, CompletionHandler $handler): int
    {
        $total = 0;

        CharacterSkill::query()
            ->select('character_id')
            ->distinct()
            ->pluck('character_id')
            ->each(function ($characterId) use ($diff, $handler, &$total) {
                $characterId = (int) $characterId;

                DB::transaction(function () use ($characterId, $diff, $handler, &$total) {
                    $current = CharacterSkill::where('character_id', $characterId)
                        ->pluck('trained_skill_level', 'skill_id')
                        ->map(fn ($lvl) => (int) $lvl)
                        ->toArray();

                    if (empty($current)) {
                        return;
                    }

                    $snapshot = SkillSnapshot::where('character_id', $characterId)
                        ->pluck('trained_skill_level', 'skill_id')
                        ->map(fn ($lvl) => (int) $lvl)
                        ->toArray();

                    // First time we have ever seen this character: baseline silently.
                    if (empty($snapshot)) {
                        $this->writeSnapshot($characterId, $current);
                        return;
                    }

                    $completions = $diff->diff($snapshot, $current);

                    if (! empty($completions)) {
                        $handler->handle($characterId, $completions);
                        $total += count($completions);
                    }

                    $this->writeSnapshot($characterId, $current);
                });
            });

        $this->info("skillnotify:scan complete — {$total} completion(s).");

        return self::SUCCESS;
    }

    /**
     * @param array<int,int> $levels skill_id => trained_skill_level
     */
    private function writeSnapshot(int $characterId, array $levels): void
    {
        $rows = [];
        foreach ($levels as $skillId => $level) {
            $rows[] = [
                'character_id' => $characterId,
                'skill_id' => (int) $skillId,
                'trained_skill_level' => (int) $level,
                'updated_at' => now(),
            ];
        }

        if (! empty($rows)) {
            SkillSnapshot::upsert($rows, ['character_id', 'skill_id'], ['trained_skill_level', 'updated_at']);
        }
    }
}
```

- [ ] **Step 5: Register the command and default binding (already in Task 1 provider)**

Confirm `src/SkillNotificationsServiceProvider.php` `register()` contains `$this->commands([Console\Scan::class, Console\Seed::class]);` and the `CompletionHandler` → `NotificationCompletionHandler` bind from Task 1. The test overrides the bind with `RecordingHandler`, so `NotificationCompletionHandler` need not exist yet for this task's test to pass — but the provider references it. **Temporarily** comment out the `Console\Seed::class` entry and the `CompletionHandler` bind if the class-not-found error blocks the test; re-enable in Tasks 6 and 8. (Cleaner alternative: implement the no-op binding now by completing Task 6 next.)

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Feature --filter ScanCommandTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Services/CompletionHandler.php src/Console/Scan.php tests/Feature/ScanCommandTest.php src/SkillNotificationsServiceProvider.php
git commit -m "feat: add skillnotify:scan with silent baseline and per-character diff"
```

---

## Task 5: Discord notification + alert registration

**Files:**
- Create: `src/Notifications/Discord/SkillCompleted.php`
- Create: `src/Config/notifications.alerts.php`
- Create: `src/resources/lang/en/alerts.php`
- Create: `tests/Feature/SkillCompletedNotificationTest.php`

**Interfaces:**
- Consumes: `Seat\Notifications\Notifications\AbstractDiscordNotification`, `Seat\Notifications\Services\Discord\Messages\DiscordMessage`.
- Produces: `Fside\SkillNotifications\Notifications\Discord\SkillCompleted::__construct(string $characterName, ?string $corporationName, string $skillName, int $level, int $skillPoints)`; alert key `fside_skill_completed` in config `notifications.alerts`.

- [ ] **Step 1: Re-read `DEVNOTES.md` for the real `DiscordEmbed` API**

Before writing code, open `DEVNOTES.md` (Task 1, Step 6). If the embed builder methods differ from the `title()/field()/timestamp()` used below, adapt the closure body accordingly. The rest of this task is unaffected.

- [ ] **Step 2: Write the failing test**

`tests/Feature/SkillCompletedNotificationTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Notifications\Discord\SkillCompleted;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;

class SkillCompletedNotificationTest extends TestCase
{
    public function test_alert_is_registered_in_config(): void
    {
        $alerts = config('notifications.alerts');

        $this->assertArrayHasKey('fside_skill_completed', $alerts);
        $this->assertEquals(
            SkillCompleted::class,
            $alerts['fside_skill_completed']['handlers']['discord']
        );
    }

    public function test_populate_message_sets_author_and_an_embed(): void
    {
        $notification = new SkillCompleted('Korgoroth', 'Fly Sideways', 'Caldari Battleship', 5, 256000);

        $message = new DiscordMessage();

        // populateMessage is protected; invoke via the documented via/toDiscord path.
        $reflection = new \ReflectionMethod($notification, 'populateMessage');
        $reflection->setAccessible(true);
        $reflection->invoke($notification, $message, null);

        // The username set via from() should be our monitor name.
        $this->assertStringContainsString('FSIDE Skill Monitor', json_encode($message));
        $this->assertStringContainsString('Caldari Battleship', json_encode($message));
        $this->assertStringContainsString('Korgoroth', json_encode($message));
    }
}
```

> If `json_encode($message)` does not expose the embed contents (depends on `DiscordMessage` internals noted in `DEVNOTES.md`), assert instead against the public accessor/property the class exposes for username and embeds.

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Feature --filter SkillCompletedNotificationTest`
Expected: FAIL — class/config missing.

- [ ] **Step 4: Create the alert config**

`src/Config/notifications.alerts.php`:

```php
<?php

return [
    'fside_skill_completed' => [
        'label' => 'skillnotify::alerts.skill_completed',
        'handlers' => [
            'discord' => \Fside\SkillNotifications\Notifications\Discord\SkillCompleted::class,
        ],
    ],
];
```

- [ ] **Step 5: Create the translation tokens**

`src/resources/lang/en/alerts.php`:

```php
<?php

return [
    'skill_completed' => 'Skill Completed',
];
```

- [ ] **Step 6: Implement the notification**

`src/Notifications/Discord/SkillCompleted.php`:

```php
<?php

namespace Fside\SkillNotifications\Notifications\Discord;

use Seat\Notifications\Notifications\AbstractDiscordNotification;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;

class SkillCompleted extends AbstractDiscordNotification
{
    public function __construct(
        private string $characterName,
        private ?string $corporationName,
        private string $skillName,
        private int $level,
        private int $skillPoints,
    ) {}

    protected function populateMessage(DiscordMessage $message, $notifiable)
    {
        $message
            ->from('FSIDE Skill Monitor')
            ->embed(function ($embed) {
                // NOTE: confirm these builder names against DEVNOTES.md (DiscordEmbed).
                $embed
                    ->title('A member finished training a skill')
                    ->field('Character', $this->characterName, true)
                    ->field('Corporation', $this->corporationName ?? '-', true)
                    ->field('Skill', $this->skillName, true)
                    ->field('Level', $this->toRoman($this->level), true)
                    ->field('Skill Points', number_format($this->skillPoints), true)
                    ->timestamp(now());
            });
    }

    private function toRoman(int $level): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$level] ?? (string) $level;
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Feature --filter SkillCompletedNotificationTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Notifications src/Config/notifications.alerts.php src/resources/lang/en/alerts.php tests/Feature/SkillCompletedNotificationTest.php
git commit -m "feat: add SkillCompleted Discord notification and alert registration"
```

---

## Task 6: Default handler — dispatch to notification groups

**Files:**
- Create: `src/Services/NotificationCompletionHandler.php`
- Create: `tests/Feature/NotificationDispatchTest.php`

**Interfaces:**
- Consumes: `CompletionHandler` (Task 4), `SkillCompleted` (Task 5), `Seat\Notifications\Traits\NotificationDispatchTool::dispatchNotifications($alert_type, $groups, $callback)`, `Seat\Eveapi\Models\Sde\InvType`, `Seat\Eveapi\Models\Character\CharacterInfo`, `Seat\Eveapi\Models\Character\CharacterSkill` (for `skillpoints_in_skill`).
- Produces: `Fside\SkillNotifications\Services\NotificationCompletionHandler` (bound to `CompletionHandler` in the provider).

- [ ] **Step 1: Re-read `DEVNOTES.md` for the `$groups` lookup and lookups**

Confirm from `DEVNOTES.md`: (a) how core builds the `$groups` argument for `dispatchNotifications` (the query against notification groups subscribed to an alert key); (b) the `InvType` name column; (c) how to resolve character name + corp name from `CharacterInfo`. Adapt `groupsForAlert()` and the resolve helpers below to the real API.

- [ ] **Step 2: Write the failing test**

The default binding (provider) points `CompletionHandler` at `NotificationCompletionHandler`. The test asserts that running a full scan with a real completion enqueues the SeAT notification. We use Laravel's `Notification::fake()` and assert via the dispatch path; if `dispatchNotifications` enqueues jobs rather than using the `Notification` facade directly, assert against `Bus::fake()`/`Queue::fake()` as recorded in `DEVNOTES.md`.

`tests/Feature/NotificationDispatchTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Services\NotificationCompletionHandler;
use Fside\SkillNotifications\Services\CompletionHandler;
use Fside\SkillNotifications\Services\Completion;

class NotificationDispatchTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_handler_dispatches_without_error_when_no_groups_subscribed(): void
    {
        // With zero subscribed groups, handle() must be a safe no-op (no exceptions).
        $handler = $this->app->make(CompletionHandler::class);
        $this->assertInstanceOf(NotificationCompletionHandler::class, $handler);

        $handler->handle(90000001, [new Completion(3340, 3, 4)]);

        $this->assertTrue(true); // reached here without throwing
    }
}
```

> Expand this test once `DEVNOTES.md` confirms the group/dispatch mechanism: seed one notification group subscribed to `fside_skill_completed` with a Discord integration, fake the queue/notification, call `handle()`, and assert exactly one `SkillCompleted` was enqueued with `skillName = 'Caldari Battleship'` and `level = 4`.

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Feature --filter NotificationDispatchTest`
Expected: FAIL — `NotificationCompletionHandler` not found.

- [ ] **Step 4: Implement the handler**

`src/Services/NotificationCompletionHandler.php`:

```php
<?php

namespace Fside\SkillNotifications\Services;

use Seat\Notifications\Traits\NotificationDispatchTool;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Fside\SkillNotifications\Notifications\Discord\SkillCompleted;

class NotificationCompletionHandler implements CompletionHandler
{
    use NotificationDispatchTool;

    private const ALERT = 'fside_skill_completed';

    public function handle(int $characterId, array $completions): void
    {
        $groups = $this->groupsForAlert(self::ALERT);

        if ($groups->isEmpty()) {
            return;
        }

        [$characterName, $corporationName] = $this->resolveCharacter($characterId);

        foreach ($completions as $completion) {
            $skillName = $this->resolveSkillName($completion->skillId);
            $skillPoints = (int) CharacterSkill::where('character_id', $characterId)
                ->where('skill_id', $completion->skillId)
                ->value('skillpoints_in_skill');

            $this->dispatchNotifications(
                self::ALERT,
                $groups,
                fn (string $handlerClass) => new $handlerClass(
                    $characterName,
                    $corporationName,
                    $skillName,
                    $completion->toLevel,
                    $skillPoints,
                )
            );
        }
    }

    /**
     * Notification groups subscribed to the given alert.
     * CONFIRM the exact model/query against DEVNOTES.md and adapt.
     */
    private function groupsForAlert(string $alert)
    {
        return \Seat\Notifications\Models\NotificationGroup::with('integrations')
            ->whereHas('alerts', fn ($q) => $q->where('alert', $alert))
            ->get();
    }

    private function resolveSkillName(int $skillId): string
    {
        // CONFIRM name column (typeName vs type_name) against DEVNOTES.md.
        $name = InvType::where('typeID', $skillId)->value('typeName');

        return $name ?: "Skill {$skillId}";
    }

    /**
     * @return array{0:string,1:?string} [characterName, corporationName]
     */
    private function resolveCharacter(int $characterId): array
    {
        // CONFIRM CharacterInfo accessors/relations against DEVNOTES.md.
        $info = CharacterInfo::find($characterId);

        return [
            $info->name ?? "Character {$characterId}",
            $info->corporation->name ?? null,
        ];
    }
}
```

> The three `CONFIRM` helpers are the only spots that depend on unverifiable-from-here APIs. They are deliberately isolated as private methods so adapting them to the real signatures from `DEVNOTES.md` is a one-method change each, with no impact on `handle()`'s logic.

- [ ] **Step 5: Re-enable the provider bindings**

Ensure `register()` in the service provider has the `CompletionHandler` → `NotificationCompletionHandler` bind active (un-comment if Task 4 Step 5 disabled it).

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Feature --filter NotificationDispatchTest`
Expected: PASS. Then run the full suite: `vendor/bin/phpunit`. Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Services/NotificationCompletionHandler.php tests/Feature/NotificationDispatchTest.php src/SkillNotificationsServiceProvider.php
git commit -m "feat: dispatch SkillCompleted to subscribed notification groups"
```

---

## Task 7: Scheduled scan registration

**Files:**
- Create: `src/Database/Seeders/ScheduleSeeder.php`
- Create: `tests/Feature/ScheduleSeederTest.php`

**Interfaces:**
- Consumes: `Seat\Services\Seeding\AbstractScheduleSeeder` (confirm exact FQCN in `DEVNOTES.md`; SeAT exposes an abstract schedule seeder).
- Produces: a seeder that registers `skillnotify:scan` at `*/15 * * * *`.

- [ ] **Step 1: Confirm the AbstractScheduleSeeder FQCN and shape**

In the container: `grep -rn "class AbstractScheduleSeeder" vendor/eveseat`. Record the namespace and the `getSchedules()` contract in `DEVNOTES.md`. Adapt the `extends` and method below if needed.

- [ ] **Step 2: Write the failing test**

`tests/Feature/ScheduleSeederTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Database\Seeders\ScheduleSeeder;

class ScheduleSeederTest extends TestCase
{
    public function test_seeder_registers_the_scan_command_every_15_minutes(): void
    {
        $schedules = (new ScheduleSeeder())->getSchedules();

        $scan = collect($schedules)->firstWhere('command', 'skillnotify:scan');

        $this->assertNotNull($scan, 'skillnotify:scan must be scheduled');
        $this->assertEquals('*/15 * * * *', $scan['expression']);
        $this->assertFalse($scan['allow_overlap']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Feature --filter ScheduleSeederTest`
Expected: FAIL — seeder missing.

- [ ] **Step 4: Implement the seeder**

`src/Database/Seeders/ScheduleSeeder.php`:

```php
<?php

namespace Fside\SkillNotifications\Database\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'skillnotify:scan',
                'expression' => '*/15 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }
}
```

> If `DEVNOTES.md` shows a different `AbstractScheduleSeeder` namespace or an additional required method (e.g. a key/identity method), add it here verbatim from the vendor source.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Feature --filter ScheduleSeederTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Database/Seeders/ScheduleSeeder.php tests/Feature/ScheduleSeederTest.php
git commit -m "feat: register skillnotify:scan on a 15-minute default schedule"
```

---

## Task 8: Backfill command + end-to-end smoke on Docker

**Files:**
- Create: `src/Console/Seed.php`
- Create: `tests/Feature/SeedCommandTest.php`

**Interfaces:**
- Consumes: the same baseline logic used by `Scan` (extracted to avoid duplication).
- Produces: command `skillnotify:seed` that writes baseline snapshots for all characters with zero notifications.

- [ ] **Step 1: Extract shared baseline into a reusable point**

To stay DRY, `Seed` reuses `Scan`'s snapshot write. Refactor `Scan::writeSnapshot` to a small service used by both. Create `src/Services/SnapshotWriter.php`:

```php
<?php

namespace Fside\SkillNotifications\Services;

use Fside\SkillNotifications\Models\SkillSnapshot;

class SnapshotWriter
{
    /**
     * @param array<int,int> $levels skill_id => trained_skill_level
     */
    public function write(int $characterId, array $levels): void
    {
        $rows = [];
        foreach ($levels as $skillId => $level) {
            $rows[] = [
                'character_id' => $characterId,
                'skill_id' => (int) $skillId,
                'trained_skill_level' => (int) $level,
                'updated_at' => now(),
            ];
        }

        if (! empty($rows)) {
            SkillSnapshot::upsert($rows, ['character_id', 'skill_id'], ['trained_skill_level', 'updated_at']);
        }
    }
}
```

Then in `src/Console/Scan.php`, replace the private `writeSnapshot()` body with a call to an injected `SnapshotWriter` (inject via the `handle()` signature: `handle(SkillDiff $diff, CompletionHandler $handler, SnapshotWriter $writer)` and call `$writer->write(...)`). Re-run `vendor/bin/phpunit --filter ScanCommandTest` to confirm still green.

- [ ] **Step 2: Write the failing test**

`tests/Feature/SeedCommandTest.php`:

```php
<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Models\SkillSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedCommandTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('character_skills')) {
            Schema::create('character_skills', function ($table) {
                $table->unsignedBigInteger('character_id');
                $table->integer('skill_id');
                $table->unsignedTinyInteger('trained_skill_level');
                $table->unsignedBigInteger('skillpoints_in_skill')->default(0);
                $table->primary(['character_id', 'skill_id']);
            });
        }
    }

    public function test_seed_writes_baseline_for_all_characters(): void
    {
        DB::table('character_skills')->insert([
            ['character_id' => 90000001, 'skill_id' => 3340, 'trained_skill_level' => 5, 'skillpoints_in_skill' => 1000],
            ['character_id' => 90000002, 'skill_id' => 3413, 'trained_skill_level' => 2, 'skillpoints_in_skill' => 500],
        ]);

        $this->artisan('skillnotify:seed')->assertExitCode(0);

        $this->assertEquals(2, SkillSnapshot::count());
        $this->assertEquals(5, SkillSnapshot::where('character_id', 90000001)->value('trained_skill_level'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Feature --filter SeedCommandTest`
Expected: FAIL — command missing.

- [ ] **Step 4: Implement `Seed`**

`src/Console/Seed.php`:

```php
<?php

namespace Fside\SkillNotifications\Console;

use Illuminate\Console\Command;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Fside\SkillNotifications\Services\SnapshotWriter;

class Seed extends Command
{
    protected $signature = 'skillnotify:seed';

    protected $description = 'Baseline all characters\' skill snapshots without sending notifications.';

    public function handle(SnapshotWriter $writer): int
    {
        $count = 0;

        CharacterSkill::query()
            ->select('character_id')
            ->distinct()
            ->pluck('character_id')
            ->each(function ($characterId) use ($writer, &$count) {
                $characterId = (int) $characterId;

                $levels = CharacterSkill::where('character_id', $characterId)
                    ->pluck('trained_skill_level', 'skill_id')
                    ->map(fn ($lvl) => (int) $lvl)
                    ->toArray();

                $writer->write($characterId, $levels);
                $count++;
            });

        $this->info("skillnotify:seed complete — baselined {$count} character(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Feature --filter SeedCommandTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 7: End-to-end smoke test on Docker SeAT v5**

```bash
# Install the plugin into the running SeAT via a path repository:
docker compose -f docker/docker-compose.yml exec front composer config repositories.skillnotify path /path/to/seat-skill-plugin
docker compose -f docker/docker-compose.yml exec front composer require fside/seat-skill-notifications:@dev
docker compose -f docker/docker-compose.yml exec front php artisan migrate
docker compose -f docker/docker-compose.yml exec front php artisan config:clear

# Baseline, then confirm a clean scan emits nothing:
docker compose -f docker/docker-compose.yml exec front php artisan skillnotify:seed
docker compose -f docker/docker-compose.yml exec front php artisan skillnotify:scan
```
Expected: `skillnotify:scan complete — 0 completion(s).` Then, in SeAT's UI, create a notification group with a Discord integration, enable the **Skill Completed** alert, and (for a real check) wait for a member to finish a skill — or temporarily bump a `trained_skill_level` row in `character_skills` and re-run `skillnotify:scan` to confirm one embed posts.

- [ ] **Step 8: Commit**

```bash
git add src/Console/Seed.php src/Services/SnapshotWriter.php src/Console/Scan.php tests/Feature/SeedCommandTest.php
git commit -m "feat: add skillnotify:seed backfill and DRY snapshot writer"
```

---

## Self-Review

**Spec coverage:**
- Snapshot-diff detection of `trained_skill_level` → Tasks 2, 4. ✓
- Plugin-owned `skillnotify_skill_snapshots` table, composite PK, no core FKs → Task 3. ✓
- Per-character silent first-run baseline → Task 4 (`test_first_run_baselines_silently`). ✓
- Native Discord embed via SeAT framework (`AbstractDiscordNotification`, `notifications.alerts.php`) → Task 5. ✓
- Dispatch to notification groups via `NotificationDispatchTool` → Task 6. ✓
- One embed per completion; detection decoupled from dispatch behind `CompletionHandler` (enables future filtering/batching) → Tasks 4, 6. ✓
- Scheduled scan, 15-min configurable default → Task 7. ✓
- Optional `skillnotify:seed` backfill → Task 8. ✓
- Docker SeAT v5 dev env, plugin as path repo, MariaDB → Tasks 1, 8. ✓
- TDD unit (diff) + feature (scan baseline→completion) + dispatch (fake) → Tasks 2, 4, 6. ✓

**Placeholder scan:** No `TBD`/`TODO` left as work items. The `CONFIRM`/`DEVNOTES.md` markers are deliberate verification steps for the four SeAT APIs not confirmable from outside a running v5 instance; each is isolated to a single private method or step so adaptation is local.

**Type consistency:** `Completion{skillId,fromLevel,toLevel}` used consistently (Tasks 2,4,6). `CompletionHandler::handle(int, array)` consistent (Tasks 4,6). `SkillCompleted::__construct(string,?string,string,int,int)` matches the dispatch callback in Task 6. `SnapshotWriter::write(int,array)` consumed by both `Scan` (refactored Task 8 Step 1) and `Seed`. `SkillSnapshot::upsert(rows, ['character_id','skill_id'], [...])` consistent (Tasks 3,4,8).

---

## Later phases (not in this plan — see design doc)

Phase 2 (skill plans + UI), Phase 3 (milestone notifications), Phase 4 (queue warnings + dashboard), and the configurable-per-group filtering end-state are outlined in `docs/superpowers/specs/2026-06-25-fside-skill-notifications-design.md`. The `CompletionHandler` seam introduced here is the extension point for per-group filtering and milestone annotation.
