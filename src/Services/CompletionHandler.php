<?php

namespace Fside\SkillNotifications\Services;

interface CompletionHandler
{
    /**
     * @param Completion[] $completions
     */
    public function handle(int $characterId, array $completions): void;
}
