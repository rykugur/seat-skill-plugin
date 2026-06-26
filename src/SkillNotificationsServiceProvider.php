<?php

namespace Fside\SkillNotifications;

use Seat\Services\AbstractSeatPlugin;

class SkillNotificationsServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'skillnotify');
        $this->mergeConfigFrom(__DIR__ . '/Config/notifications.alerts.php', 'notifications.alerts');

        $this->registerDatabaseSeeders(\Fside\SkillNotifications\Database\Seeders\ScheduleSeeder::class);
    }

    public function register()
    {
        $this->commands([\Fside\SkillNotifications\Console\Scan::class]);

        $this->app->bind(
            \Fside\SkillNotifications\Services\CompletionHandler::class,
            \Fside\SkillNotifications\Services\NotificationCompletionHandler::class
        );

        // Wiring below is added incrementally:
        //   Task 8: add Console\Seed::class to the commands array.
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
