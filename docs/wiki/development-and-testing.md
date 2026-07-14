# Development And Testing

Local unit and feature tests use PHPUnit and Orchestra Testbench. Production
runtime target is SeAT v5 with MariaDB.

## Test coverage

The suite covers:

- `SkillDiff` increases, multi-level jumps, unchanged levels, decreases, and
  multiple-skill input.
- first-run silent baselining in `skillnotify:scan`
- one completion after a post-baseline level increase
- reruns with no changes
- pending-completion retry behavior after notification failure
- `skillnotify:seed`
- schedule seeder defaults
- notification alert registration
- Discord embed content
- notification group affiliation filtering

Run tests with:

```bash
composer install
vendor/bin/phpunit
```

In the Nix devshell, use:

```bash
nix develop --command bash -lc 'composer install'
nix develop --command bash -lc 'vendor/bin/phpunit'
```

## Docker smoke test

The local `docker/docker-compose.yml` starts SeAT v5 with MariaDB and Redis.
The plugin checkout is mounted into `/var/www/seat/packages/seat-skill-plugin`.

The verified smoke test used:

- character ID `90000001`
- corporation ID `98000001`
- skill ID `3340`
- skill name `Caldari Battleship`
- baseline level IV
- injected level V

`skillnotify:scan` reported one completion and the worker processed:

```text
Fside\SkillNotifications\Notifications\Discord\SkillCompleted DONE
```

The first delivery attempt failed before worker restart because the worker had
been running before the plugin class was installed. Restarting `seat-worker`
resolved the autoloader issue.

## Dev compose persistence

The dev compose stack persists `/var/www/seat` in the `seat-app` named volume so
Composer-installed packages survive container recreation. `docker compose down
-v` intentionally deletes named volumes and requires reinstalling the package.

