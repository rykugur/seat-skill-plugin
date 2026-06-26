<?php

namespace Fside\SkillNotifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Fside\SkillNotifications\Models\SkillSnapshot;
use Fside\SkillNotifications\Services\SkillDiff;
use Fside\SkillNotifications\Services\CompletionHandler;
use Fside\SkillNotifications\Services\SnapshotWriter;

class Scan extends Command
{
    protected $signature = 'skillnotify:scan';

    protected $description = 'Detect skill-training completions and notify enabled channels.';

    public function handle(SkillDiff $diff, CompletionHandler $handler, SnapshotWriter $writer): int
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

        $characterIds->each(function ($characterId) use ($diff, $handler, $writer, &$total) {
            $characterId = (int) $characterId;

            DB::transaction(function () use ($characterId, $diff, $handler, $writer, &$total) {
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
                    $writer->write($characterId, $current);
                    return;
                }

                $completions = $diff->diff($snapshot, $current);

                if (! empty($completions)) {
                    $handler->handle($characterId, $completions);
                    $total += count($completions);
                }

                $writer->write($characterId, $current);
            });
        });

        $this->info("skillnotify:scan complete — {$total} completion(s).");

        return self::SUCCESS;
    }
}
