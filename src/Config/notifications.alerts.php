<?php

return [
    'fside_skill_completed' => [
        'label' => 'skillnotify::alerts.skill_completed',
        'handlers' => [
            'discord' => \Fside\SkillNotifications\Notifications\Discord\SkillCompleted::class,
        ],
    ],
];
