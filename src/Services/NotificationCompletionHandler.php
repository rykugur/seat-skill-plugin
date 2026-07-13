<?php

namespace Fside\SkillNotifications\Services;

use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Notifications\Traits\NotificationDispatchTool;

class NotificationCompletionHandler implements CompletionHandler
{
    use NotificationDispatchTool;

    private const ALERT = 'fside_skill_completed';

    public function handle(int $characterId, array $completions): void
    {
        [$characterName, $corporationName, $corporationId] = $this->resolveCharacter($characterId);

        $groups = $this->groupsForAlert(self::ALERT, $characterId, $corporationId);

        if ($groups->isEmpty()) {
            return;
        }

        foreach ($completions as $completion) {
            $skillName = $this->resolveSkillName($completion->skillId);
            $skillPoints = (int) DB::table('character_skills')
                ->where('character_id', $characterId)
                ->where('skill_id', $completion->skillId)
                ->value('skillpoints_in_skill');

            $this->dispatchNotifications(
                self::ALERT,
                $groups,
                fn (string $handlerClass) => new $handlerClass(
                    $characterName,
                    $corporationName,
                    $skillName,
                    $completion->toLevel,
                    $skillPoints,
                )
            );
        }
    }

    /**
     * Return notification groups subscribed to the given alert key.
     * Protected so tests can override with a fake collection.
     */
    protected function groupsForAlert(string $alert, int $characterId, ?int $corporationId)
    {
        return \Seat\Notifications\Models\NotificationGroup::with('alerts', 'affiliations', 'integrations', 'mentions')
            ->whereHas('alerts', fn ($q) => $q->where('alert', $alert))
            ->whereHas('affiliations', function ($q) use ($characterId, $corporationId) {
                $q->where('affiliation_id', $characterId);

                if ($corporationId !== null) {
                    $q->orWhere('affiliation_id', $corporationId);
                }
            })
            ->get();
    }

    /**
     * Resolve a skill name from the SDE invTypes table.
     * Falls back to "Skill {$skillId}" if the row is absent.
     */
    protected function resolveSkillName(int $skillId): string
    {
        return InvType::where('typeID', $skillId)->value('typeName') ?? "Skill {$skillId}";
    }

    /**
     * Resolve character name and corporation name for the given character ID.
     * Never throws — corporation name is best-effort and may be null.
     *
     * @return array{0: string, 1: ?string, 2: ?int}
     */
    protected function resolveCharacter(int $characterId): array
    {
        $info = CharacterInfo::find($characterId);

        $characterName = $info->name ?? "Character {$characterId}";
        $corporationId = rescue(
            fn () => isset($info?->affiliation?->corporation_id) ? (int) $info->affiliation->corporation_id : null,
            null,
            false
        );

        // Corporation name requires CharacterAffiliation -> UniverseName chain which
        // may be absent in test/minimal environments.  Wrap in rescue() so any missing
        // relation, table, or null simply yields null rather than throwing.
        $corporationName = rescue(
            fn () => $info?->affiliation?->corporation?->name ?? null,
            null,
            false // don't report to exception handler
        );

        return [$characterName, $corporationName, $corporationId];
    }
}
