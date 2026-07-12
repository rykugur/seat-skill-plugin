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

class FailingOnceHandler implements CompletionHandler
{
    /** @var array<int,Completion[]> */
    public array $calls = [];
    public int $failuresRemaining = 1;

    public function handle(int $characterId, array $completions): void
    {
        $this->calls[$characterId][] = $completions[0];

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException('simulated notification failure');
        }
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

    public function test_notification_failure_keeps_snapshot_advanced_and_retries_pending_completion(): void
    {
        $this->setSkill(90000001, 3340, 3);
        $this->artisan('skillnotify:scan'); // baseline

        $handler = new FailingOnceHandler();
        $this->app->instance(CompletionHandler::class, $handler);

        $this->setSkill(90000001, 3340, 4);

        try {
            $this->artisan('skillnotify:scan');
            $this->fail('Expected the first notification attempt to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated notification failure', $e->getMessage());
        }

        $this->assertEquals(
            4,
            SkillSnapshot::where('character_id', 90000001)->where('skill_id', 3340)->value('trained_skill_level')
        );

        $this->artisan('skillnotify:scan')->assertExitCode(0);

        $this->assertCount(2, $handler->calls[90000001]);
        $this->assertSame(4, $handler->calls[90000001][1]->toLevel);
    }
}
