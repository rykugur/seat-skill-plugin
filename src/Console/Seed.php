<?php

namespace Fside\SkillNotifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Fside\SkillNotifications\Services\SnapshotWriter;

class Seed extends Command
{
    protected $signature = 'skillnotify:seed';

    protected $description = 'Baseline all characters\' skill snapshots without sending notifications.';

    public function handle(SnapshotWriter $writer): int
    {
        $count = 0;

        // Use DB::table to avoid CharacterSkill model scopes/casts against the
        // stand-in table used in tests — same pattern as Scan.php.
        $characterIds = DB::table('character_skills')
            ->select('character_id')
            ->distinct()
            ->pluck('character_id');

        $characterIds->each(function ($characterId) use ($writer, &$count) {
            $characterId = (int) $characterId;

            $levels = DB::table('character_skills')
                ->where('character_id', $characterId)
                ->pluck('trained_skill_level', 'skill_id')
                ->map(fn ($lvl) => (int) $lvl)
                ->toArray();

            $writer->write($characterId, $levels);
            $count++;
        });

        $this->info("skillnotify:seed complete — baselined {$count} character(s).");

        return self::SUCCESS;
    }
}
