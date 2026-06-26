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
