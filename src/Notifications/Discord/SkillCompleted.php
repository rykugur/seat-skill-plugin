<?php

namespace Fside\SkillNotifications\Notifications\Discord;

use Seat\Notifications\Notifications\AbstractDiscordNotification;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbed;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;

class SkillCompleted extends AbstractDiscordNotification
{
    public function __construct(
        private string $characterName,
        private ?string $corporationName,
        private string $skillName,
        private int $level,
        private int $skillPoints,
    ) {}

    protected function populateMessage(DiscordMessage $message, $notifiable): void
    {
        $message
            ->from('FSIDE Skill Monitor')
            ->embed(function (DiscordEmbed $embed) {
                $embed
                    ->title('A member finished training a skill')
                    ->field('Character', $this->characterName)
                    ->field('Corporation', $this->corporationName ?? '-')
                    ->field('Skill', $this->skillName)
                    ->field('Level', $this->toRoman($this->level))
                    ->field('Skill Points', number_format($this->skillPoints))
                    ->field('Completed', now()->toIso8601String())
                    ->timestamp(now());
            });
    }

    private function toRoman(int $level): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$level] ?? (string) $level;
    }
}
