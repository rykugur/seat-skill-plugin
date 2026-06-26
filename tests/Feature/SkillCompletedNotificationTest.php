<?php

namespace Fside\SkillNotifications\Tests\Feature;

use Fside\SkillNotifications\Notifications\Discord\SkillCompleted;
use Fside\SkillNotifications\Tests\TestCase;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbed;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;

class SkillCompletedNotificationTest extends TestCase
{
    public function test_alert_is_registered_in_config(): void
    {
        $alerts = config('notifications.alerts');

        $this->assertArrayHasKey('fside_skill_completed', $alerts);
        $this->assertEquals(
            SkillCompleted::class,
            $alerts['fside_skill_completed']['handlers']['discord']
        );
    }

    public function test_populate_message_sets_author_and_embed_with_expected_content(): void
    {
        $notification = new SkillCompleted('Korgoroth', 'Fly Sideways', 'Caldari Battleship', 5, 256000);

        $message = new DiscordMessage();

        // populateMessage is protected; invoke via reflection.
        $reflection = new \ReflectionMethod($notification, 'populateMessage');
        $reflection->setAccessible(true);
        $reflection->invoke($notification, $message, null);

        // DiscordMessage::$username is a public property set by ->from(...).
        // DiscordMessage::$embeds is a public array of DiscordEmbed objects whose
        // properties are also public — so json_encode($message) exposes all of this.
        // The simple field($title, $value) form stores fields as an associative array
        // (not DiscordEmbedField objects), so field names and values appear in JSON.
        $json = json_encode($message);

        // Author / username
        $this->assertStringContainsString('FSIDE Skill Monitor', $json,
            'username must be "FSIDE Skill Monitor"');

        // Embed title
        $this->assertStringContainsString('A member finished training a skill', $json,
            'embed title must be present');

        // Field: Character name
        $this->assertStringContainsString('Korgoroth', $json,
            'character name must appear in a field');

        // Field: Skill name
        $this->assertStringContainsString('Caldari Battleship', $json,
            'skill name must appear in a field');

        // Field: Level as roman numeral V
        $this->assertStringContainsString('"V"', $json,
            'level 5 must render as roman numeral V');

        // Structural check: exactly one embed was added
        $this->assertCount(1, $message->embeds);

        // Structural check: embed title is set directly
        /** @var DiscordEmbed $embed */
        $embed = $message->embeds[0];
        $this->assertSame('A member finished training a skill', $embed->title);

        // Fields are stored as associative array by the simple field() form
        $this->assertArrayHasKey('Character', $embed->fields);
        $this->assertSame('Korgoroth', $embed->fields['Character']);

        $this->assertArrayHasKey('Skill', $embed->fields);
        $this->assertSame('Caldari Battleship', $embed->fields['Skill']);

        $this->assertArrayHasKey('Level', $embed->fields);
        $this->assertSame('V', $embed->fields['Level']);

        // Field: Corporation
        $this->assertArrayHasKey('Corporation', $embed->fields);
        $this->assertSame('Fly Sideways', $embed->fields['Corporation']);

        // Field: Skill Points (formatted with thousands separator)
        $this->assertArrayHasKey('Skill Points', $embed->fields);
        $this->assertStringContainsString('256,000', $embed->fields['Skill Points']);
    }
}
