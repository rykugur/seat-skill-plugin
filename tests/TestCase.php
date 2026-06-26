<?php

namespace Fside\SkillNotifications\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Fside\SkillNotifications\SkillNotificationsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SkillNotificationsServiceProvider::class];
    }
}
