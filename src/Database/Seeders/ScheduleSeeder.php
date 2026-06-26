<?php

namespace Fside\SkillNotifications\Database\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function getSchedules(): array
    {
        return [
            [
                'command'           => 'skillnotify:scan',
                'expression'        => '*/15 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],
        ];
    }

    public function getDeprecatedSchedules(): array
    {
        return [];
    }
}
