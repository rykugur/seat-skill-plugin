<?php

namespace Fside\SkillNotifications;

use Seat\Services\AbstractSeatPlugin;

class SkillNotificationsServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'skillnotify');

        // Register the notification alert into core config.
        $this->mergeConfigFrom(__DIR__ . '/Config/notifications.alerts.php', 'notifications.alerts');

        // Register the scheduled scan default cadence.
        $this->registerDatabaseSeeders(Database\Seeders\ScheduleSeeder::class);
    }

    public function register()
    {
        $this->commands([
            Console\Scan::class,
            Console\Seed::class,
        ]);

        // Default completion handler -> dispatches Discord notifications.
        $this->app->bind(
            Services\CompletionHandler::class,
            Services\NotificationCompletionHandler::class
        );
    }

    public function getName(): string
    {
        return 'Skill Notifications';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/fside/seat-skill-notifications';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-skill-notifications';
    }

    public function getPackagistVendorName(): string
    {
        return 'fside';
    }
}
