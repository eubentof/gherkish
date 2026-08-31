<?php

declare(strict_types=1);

namespace Gherkish\Tests;

use Gherkish\GherkishServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GherkishServiceProvider::class];
    }
}
