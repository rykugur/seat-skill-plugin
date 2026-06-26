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
