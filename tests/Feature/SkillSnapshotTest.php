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
