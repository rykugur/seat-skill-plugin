<?php

namespace Fside\SkillNotifications\Services;

use Fside\SkillNotifications\Models\SkillSnapshot;

class SnapshotWriter
{
    /**
     * @param array<int,int> $levels skill_id => trained_skill_level
     */
    public function write(int $characterId, array $levels): void
    {
        $rows = [];
        foreach ($levels as $skillId => $level) {
            $rows[] = [
                'character_id' => $characterId,
                'skill_id' => (int) $skillId,
                'trained_skill_level' => (int) $level,
                'updated_at' => now(),
            ];
        }

        if (! empty($rows)) {
            SkillSnapshot::upsert($rows, ['character_id', 'skill_id'], ['trained_skill_level', 'updated_at']);
        }
    }
}
