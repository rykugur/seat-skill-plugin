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

    protected function groupsForAlert(string $alert)
    {
        return $this->fakeGroups;
    }

    // Override the trait's enqueue: capture the built notification instead of sending.
    public function dispatchNotifications($alert_type, $groups, $notification_creation_callback)
    {
        $this->built[] = $notification_creation_callback(SkillCompleted::class);
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

        DB::table('invTypes')->insert(['typeID' => 3340, 'typeName' => 'Caldari Battleship']);
        DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => 'Korgoroth']);
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
}
