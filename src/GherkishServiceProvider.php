<?php

declare(strict_types=1);

namespace Gherkish;

use Gherkish\Console\CheckFeaturesCommand;
use Illuminate\Support\ServiceProvider;

final class GherkishServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(CheckFeaturesCommand::class);
        }
    }
}
