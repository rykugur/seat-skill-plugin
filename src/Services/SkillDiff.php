<?php

namespace Fside\SkillNotifications\Services;

class SkillDiff
{
    /**
     * @param array<int,int> $snapshot skill_id => trained_skill_level (last accounted)
     * @param array<int,int> $current  skill_id => trained_skill_level (current truth)
     * @return Completion[]
     */
    public function diff(array $snapshot, array $current): array
    {
        $completions = [];

        foreach ($current as $skillId => $level) {
            $previous = $snapshot[$skillId] ?? 0;
            if ($level > $previous) {
                $completions[] = new Completion((int) $skillId, (int) $previous, (int) $level);
            }
        }

        return $completions;
    }
}
