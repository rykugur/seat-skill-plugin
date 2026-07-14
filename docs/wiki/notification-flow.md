# Notification Flow

The plugin uses SeAT's native notification framework instead of custom webhook
code.

## Alert registration

`src/Config/notifications.alerts.php` registers:

```php
'fside_skill_completed' => [
    'label' => 'skillnotify::alerts.skill_completed',
    'handlers' => [
        'discord' => \Fside\SkillNotifications\Notifications\Discord\SkillCompleted::class,
    ],
]
```

After package discovery and cache clearing, SeAT exposes this alert in the group
alert picker as `Skill Completed`.

## Group matching

`NotificationCompletionHandler::groupsForAlert()` selects
`Seat\Notifications\Models\NotificationGroup` rows that:

- have a `group_alerts.alert` value of `fside_skill_completed`
- have a `group_affiliations.affiliation_id` matching the character ID or the
  character's corporation ID

An empty affiliation list means the group will not receive this plugin's
notifications. This matches SeAT's native notification observers.

## Discord payload

`Notifications\Discord\SkillCompleted` extends
`Seat\Notifications\Notifications\AbstractDiscordNotification`.

The embed includes:

- source name: `FSIDE Skill Monitor`
- title: `A member finished training a skill`
- character
- corporation, if resolvable
- skill name
- level as a Roman numeral
- skill points
- completed timestamp

Discord delivery is queued by Laravel/SeAT. If a plugin is installed into an
already-running worker, restart the worker so queued jobs can unserialize plugin
classes.

