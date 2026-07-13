<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Tests\TestCase;
use Fside\SkillNotifications\Services\NotificationCompletionHandler;
use Fside\SkillNotifications\Services\Completion;
use Fside\SkillNotifications\Notifications\Discord\SkillCompleted;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CapturingHandler extends NotificationCompletionHandler
{
    public Collection $fakeGroups;
    /** @var SkillCompleted[] */
    public array $built = [];
    /** @var array<int,string> */
    public array $groupNames = [];

    protected function groupsForAlert(string $alert, int $characterId, ?int $corporationId)
    {
        return $this->fakeGroups;
    }

    // Override the trait's enqueue: capture the built notification instead of sending.
    public function dispatchNotifications($alert_type, $groups, $notification_creation_callback)
    {
        $this->groupNames = $groups->pluck('name')->all();
        $this->built[] = $notification_creation_callback(SkillCompleted::class);
    }
}

class RealGroupCapturingHandler extends NotificationCompletionHandler
{
    /** @var array<int,string> */
    public array $groupNames = [];

    public function dispatchNotifications($alert_type, $groups, $notification_creation_callback)
    {
        $this->groupNames = $groups->pluck('name')->all();
    }
}

class NotificationDispatchTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in tables for what resolveSkillName / resolveCharacter / skill-points read.
        // Table and column names match DEVNOTES.md findings.
        if (! Schema::hasTable('character_skills')) {
            Schema::create('character_skills', function ($t) {
                $t->unsignedBigInteger('character_id');
                $t->integer('skill_id');
                $t->unsignedTinyInteger('trained_skill_level');
                $t->unsignedBigInteger('skillpoints_in_skill')->default(0);
                $t->primary(['character_id', 'skill_id']);
            });
        }
        if (! Schema::hasTable('invTypes')) {
            Schema::create('invTypes', function ($t) {
                $t->integer('typeID')->primary();
                $t->string('typeName');
            });
        }
        if (! Schema::hasTable('character_infos')) {
            Schema::create('character_infos', function ($t) {
                $t->unsignedBigInteger('character_id')->primary();
                $t->string('name');
            });
        }
        if (! Schema::hasTable('character_affiliations')) {
            Schema::create('character_affiliations', function ($t) {
                $t->unsignedBigInteger('character_id')->primary();
                $t->unsignedBigInteger('corporation_id')->nullable();
            });
        }
        if (! Schema::hasTable('notification_groups')) {
            Schema::create('notification_groups', function ($t) {
                $t->increments('id');
                $t->string('name');
                $t->string('type')->default('custom');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('integrations')) {
            Schema::create('integrations', function ($t) {
                $t->increments('id');
                $t->string('name');
                $t->string('type');
                $t->text('settings');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('integration_notification_group')) {
            Schema::create('integration_notification_group', function ($t) {
                $t->integer('integration_id')->unsigned()->index();
                $t->integer('notification_group_id')->unsigned()->index();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('group_alerts')) {
            Schema::create('group_alerts', function ($t) {
                $t->increments('id');
                $t->integer('notification_group_id')->unsigned();
                $t->string('alert');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('group_affiliations')) {
            Schema::create('group_affiliations', function ($t) {
                $t->increments('id');
                $t->integer('notification_group_id')->unsigned();
                $t->string('type');
                $t->integer('affiliation_id');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('notification_groups_mentions')) {
            Schema::create('notification_groups_mentions', function ($t) {
                $t->increments('id');
                $t->integer('notification_group_id')->unsigned();
                $t->string('type');
                $t->string('data');
                $t->timestamps();
            });
        }

        DB::table('invTypes')->insert(['typeID' => 3340, 'typeName' => 'Caldari Battleship']);
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => 'Korgoroth']);
        DB::table('character_affiliations')->insert(['character_id' => 90000001, 'corporation_id' => 98000001]);
        DB::table('character_skills')->insert([
            'character_id' => 90000001,
            'skill_id' => 3340,
            'trained_skill_level' => 5,
            'skillpoints_in_skill' => 256000,
        ]);
    }

    public function test_no_groups_means_no_dispatch(): void
    {
        $handler = new CapturingHandler();
        $handler->fakeGroups = collect();

        $handler->handle(90000001, [new Completion(3340, 4, 5)]);

        $this->assertSame([], $handler->built);
    }

    public function test_builds_one_notification_per_completion_with_resolved_fields(): void
    {
        $handler = new CapturingHandler();
        $handler->fakeGroups = collect(['group-1']); // non-empty sentinel

        $handler->handle(90000001, [new Completion(3340, 4, 5)]);

        $this->assertCount(1, $handler->built);

        $message = new DiscordMessage();
        $ref = new \ReflectionMethod($handler->built[0], 'populateMessage');
        $ref->setAccessible(true);
        $ref->invoke($handler->built[0], $message, null);
        $json = json_encode($message);

        $this->assertStringContainsString('Caldari Battleship', $json);
        $this->assertStringContainsString('Korgoroth', $json);
        $this->assertStringContainsString('"Level":"V"', $json); // level 5 -> roman V
    }

    public function test_real_group_lookup_only_dispatches_to_matching_affiliations(): void
    {
        $this->createNotificationGroup('character match', 90000001);
        $this->createNotificationGroup('corporation match', 98000001);
        $this->createNotificationGroup('wrong corporation', 98000002);
        $this->createNotificationGroup('unaffiliated group', null);

        $handler = new RealGroupCapturingHandler();

        $handler->handle(90000001, [new Completion(3340, 4, 5)]);

        $this->assertEqualsCanonicalizing(
            ['character match', 'corporation match'],
            $handler->groupNames
        );
        $this->assertNotContains('wrong corporation', $handler->groupNames);
        $this->assertNotContains('unaffiliated group', $handler->groupNames);
    }

    private function createNotificationGroup(string $name, ?int $affiliationId): int
    {
        $groupId = DB::table('notification_groups')->insertGetId([
            'name' => $name,
            'type' => 'custom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('group_alerts')->insert([
            'notification_group_id' => $groupId,
            'alert' => 'fside_skill_completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($affiliationId !== null) {
            DB::table('group_affiliations')->insert([
                'notification_group_id' => $groupId,
                'type' => 'character',
                'affiliation_id' => $affiliationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $groupId;
    }
}
