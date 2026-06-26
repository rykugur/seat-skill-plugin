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
