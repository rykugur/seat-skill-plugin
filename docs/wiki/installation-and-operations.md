# Installation And Operations

The plugin installs into an existing SeAT v5 instance as a Composer package.
The same package works whether SeAT runs on bare metal or in Docker.

## GitHub VCS install

Until the package is published on Packagist, register the GitHub repository in
the SeAT application directory:

```bash
composer config repositories.skillnotify vcs git@github.com:rykugur/seat-skill-plugin.git
composer require fside/seat-skill-notifications:dev-master
php artisan migrate --force
php artisan optimize:clear
php artisan skillnotify:seed
```

Restart SeAT queue workers after install or update.

## Local path install

For development against a local checkout:

```bash
composer config repositories.skillnotify path /path/to/seat-skill-plugin
composer require fside/seat-skill-notifications:@dev
php artisan migrate --force
php artisan optimize:clear
php artisan skillnotify:seed
```

In Docker, run these commands inside the SeAT web container, then restart the
worker container.

## SeAT setup

In SeAT:

1. Open Notifications, or browse directly to `/notifications/groups`.
2. Create a notification group.
3. Add a character or corporation affiliation.
4. Add a Discord integration using a Discord webhook URL.
5. Add the `Skill Completed` alert to the group.

The affiliation step is required. Groups without matching affiliations do not
receive skill-completion events.

## Runtime commands

`skillnotify:seed` baselines all current characters without sending
notifications.

`skillnotify:scan` detects new completions and dispatches pending notifications.
The default schedule is every 15 minutes via SeAT's schedule seeder.

