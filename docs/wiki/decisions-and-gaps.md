# Decisions And Gaps

## Key decisions

- Read SeAT's synced tables only; never call ESI directly.
- Detect completions by snapshot diffing `character_skills.trained_skill_level`.
- Use SeAT notification groups and integrations instead of custom webhook code.
- Use MariaDB/SeAT's default connection in production.
- Avoid foreign keys into SeAT core tables.
- Keep detection separate from notification delivery through `CompletionHandler`.
- Record completion rows durably before dispatching notifications.

## Resolved SeAT v5 quirks

- `character_infos.corporation_id` no longer exists in SeAT v5. Use
  `CharacterInfo::affiliation->corporation_id`.
- Skill names come from SDE `invTypes.typeName`.
- SeAT notification groups require matching affiliations.
- Queued workers must be restarted after installing the package into a running
  instance so they can load plugin classes.
- `DiscordEmbed::field()` does not take an inline boolean parameter.

## Open gaps

- The package is currently installed from GitHub VCS or a local path repository,
  not Packagist.
- No SeAT web UI is included in Phase 1.
- Only Discord is implemented as a notification handler.
- There is no batching mode; each completion becomes its own notification.
- Future phases should decide whether richer filters belong in SeAT group
  configuration, plugin-owned tables, or both.

