<?php

declare(strict_types=1);

namespace Gherkish;

use Gherkish\Console\CheckFeaturesCommand;
use Gherkish\FeatureParity\GitStagedFileResolver;
use Gherkish\FeatureParity\StagedFileResolver;
use Illuminate\Support\ServiceProvider;

final class GherkishServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StagedFileResolver::class, GitStagedFileResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(CheckFeaturesCommand::class);
        }
    }
}
