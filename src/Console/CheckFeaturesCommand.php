<?php

namespace Gherkish\Console;

use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\FeatureParity\FeatureParityConfigurationException;
use Gherkish\FeatureParity\FeatureParityResult;
use Illuminate\Console\Command;

class CheckFeaturesCommand extends Command
{
    protected $signature = 'gherkish:check '
        .'{--dir= : Limit the check to feature files inside this directory}'
        .'{--feature= : Only check a specific feature file}'
        .'{--file= : Alias for --feature}'
        .'{--f= : Alias for --feature}'
        .'{--snapshot= : Write the coverage snapshot JSON to the given path}';

    protected $description = 'Verify that every feature scenario has a matching Pest implementation.';

    /**
     * @var array<string, string|null>
     */
    private array $envBackup = [];

    private bool $selectionDirty = false;

    public function handle(): int
    {
        $this->captureEnvState();
        $this->applyRuntimeOverrides();

        $result = null;
        $exitCode = self::SUCCESS;

        try {
            $result = FeatureParityChecker::run();
        } catch (FeatureParityConfigurationException $exception) {
            $this->error($exception->getMessage());
            $exitCode = self::FAILURE;
        }

        FeatureParityChecker::maybeWriteSnapshot();

        if ($result instanceof FeatureParityResult) {
            $this->renderResult($result);
            $exitCode = $result->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        $this->restoreEnvState();

        return $exitCode;
    }

    private function captureEnvState(): void
    {
        foreach (['FEATURE_PARITY_DIR', 'FEATURE_PARITY_FILE', 'FEATURE_PARITY_FEATURE', 'FEATURE_PARITY_SNAPSHOT'] as $key) {
            $value = getenv($key);
            $this->envBackup[$key] = $value === false ? null : $value;
        }
    }

    private function applyRuntimeOverrides(): void
    {
        $dir = $this->option('dir');
        if (is_string($dir) && $dir !== '') {
            $this->setEnv('FEATURE_PARITY_DIR', $dir, affectsSelection: true);
        }

        $feature = $this->option('feature');
        $file = $this->option('file');
        $short = $this->option('f');
        $targetFile = null;
        if (is_string($feature) && $feature !== '') {
            $targetFile = $feature;
        } elseif (is_string($file) && $file !== '') {
            $targetFile = $file;
        } elseif (is_string($short) && $short !== '') {
            $targetFile = $short;
        }

        if ($targetFile !== null) {
            $this->setEnv('FEATURE_PARITY_FILE', $targetFile, affectsSelection: true);
        }

        $snapshot = $this->option('snapshot');
        if (is_string($snapshot) && $snapshot !== '') {
            $this->setEnv('FEATURE_PARITY_SNAPSHOT', $snapshot);
        }

        if ($this->selectionDirty) {
            FeatureParityChecker::resetSelection();
        }
    }

    private function restoreEnvState(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null || $value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv(sprintf('%s=%s', $key, $value));
                $_ENV[$key] = $value;
            }
        }

        $this->selectionDirty = false;
        FeatureParityChecker::resetSelection();
    }

    private function setEnv(string $key, string $value, bool $affectsSelection = false): void
    {
        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;

        if ($affectsSelection) {
            $this->selectionDirty = true;
        }
    }

    private function renderResult(FeatureParityResult $result): void
    {
        $this->newLine();

        if ($result->hasErrors()) {
            $this->error('❌ Missing mappings detected:');
            foreach ($result->errors as $error) {
                $this->line(sprintf('  • %s', $error['label']));
                $this->line($error['message']);
                $this->newLine();
            }
        } else {
            $passed = count($result->successes);
            $this->info(sprintf('✅ All %d documented scenarios are mapped to Pest tests.', $passed));
        }

        if ($result->skipped !== []) {
            $this->comment('⚠️  Skipped scenarios:');
            foreach ($result->skipped as $skip) {
                $this->line(sprintf('  • %s — %s', $skip['label'], $skip['message']));
            }
        }

        $this->line(sprintf('Scenarios processed: %d', $result->scenarios));
        $this->line(sprintf('Failures: %d | Skipped: %d', count($result->errors), count($result->skipped)));
    }
}
