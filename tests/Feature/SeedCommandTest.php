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
