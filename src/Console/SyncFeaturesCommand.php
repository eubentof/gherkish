<?php

declare(strict_types=1);

namespace Gherkish\Console;

use Gherkish\Examples\ExamplesException;
use Gherkish\Mapping\FeatureMap;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class SyncFeaturesCommand extends Command
{
    protected $signature = 'gherkish:sync {--dry-run : Show the files that would change without writing them}';

    protected $description = 'Synchronize test @feature annotations and feature @tests declarations.';

    public function handle(): int
    {
        try {
            $changes = (new FeatureMap(base_path()))->synchronize(! $this->option('dry-run'));
        } catch (ExamplesException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        }
        foreach ($changes as $path) {
            $this->line(OutputFormatter::escape($path));
        }
        $this->info(sprintf('%d file(s) %s.', count($changes), $this->option('dry-run') ? 'would change' : 'synchronized'));

        return self::SUCCESS;
    }
}
