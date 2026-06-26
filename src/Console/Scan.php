<?php

namespace Fside\SkillNotifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Fside\SkillNotifications\Models\SkillSnapshot;
use Fside\SkillNotifications\Services\SkillDiff;
use Fside\SkillNotifications\Services\CompletionHandler;

class Scan extends Command
{
    protected $signature = 'skillnotify:scan';

    protected $description = 'Detect skill-training completions and notify enabled channels.';

    public function handle(SkillDiff $diff, CompletionHandler $handler): int
    {
        $total = 0;

        // Use DB::table instead of the CharacterSkill Eloquent model to avoid
        // global scopes, appended attributes, and casts that the model may apply
        // which could break queries against the minimal stand-in table used in tests.
        // The real SeAT character_skills table has the same columns; this is safe.
        $characterIds = DB::table('character_skills')
            ->select('character_id')
            ->distinct()
            ->pluck('character_id');

        $characterIds->each(function ($characterId) use ($diff, $handler, &$total) {
            $characterId = (int) $characterId;

            DB::transaction(function () use ($characterId, $diff, $handler, &$total) {
                $current = DB::table('character_skills')
                    ->where('character_id', $characterId)
                    ->pluck('trained_skill_level', 'skill_id')
                    ->map(fn ($lvl) => (int) $lvl)
                    ->toArray();

                if (empty($current)) {
                    return;
                }

                $snapshot = SkillSnapshot::where('character_id', $characterId)
                    ->pluck('trained_skill_level', 'skill_id')
                    ->map(fn ($lvl) => (int) $lvl)
                    ->toArray();

                // First time we have ever seen this character: baseline silently.
                if (empty($snapshot)) {
                    $this->writeSnapshot($characterId, $current);
                    return;
                }

                $completions = $diff->diff($snapshot, $current);

                if (! empty($completions)) {
                    $handler->handle($characterId, $completions);
                    $total += count($completions);
                }

                $this->writeSnapshot($characterId, $current);
            });
        });

        $this->info("skillnotify:scan complete — {$total} completion(s).");

        return self::SUCCESS;
    }

    /**
     * @param array<int,int> $levels skill_id => trained_skill_level
     */
    private function writeSnapshot(int $characterId, array $levels): void
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
