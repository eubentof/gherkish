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
        .'{--check-outline-datasets : Validate Scenario Outline datasets against their Examples tables}'
        .'{--snapshot= : Write the coverage snapshot JSON to the given path}';

    protected $description = 'Verify that every feature scenario has a matching Pest implementation.';

    /**
     * @var array<string, string|null>
     */
    private array $envBackup = [];

    private bool $selectionDirty = false;

    public function handle(): int
    {
        $this->enableAnsiColors();
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
        foreach (['FEATURE_PARITY_DIR', 'FEATURE_PARITY_FILE', 'FEATURE_PARITY_FEATURE', 'FEATURE_PARITY_CHECK_OUTLINE_DATASETS', 'FEATURE_PARITY_SNAPSHOT'] as $key) {
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

        if ($this->option('check-outline-datasets')) {
            $this->setEnv('FEATURE_PARITY_CHECK_OUTLINE_DATASETS', '1');
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
        $failures = [];

        foreach ($result->cases as $case) {
            $status = match ($case['status']) {
                'passed' => 'COVERED',
                'failed' => 'FAIL',
                default => 'WARN',
            };
            $style = match ($status) {
                'COVERED' => 'fg=black;bg=green;options=bold',
                'FAIL' => 'fg=white;bg=red;options=bold',
                default => 'fg=black;bg=yellow;options=bold',
            };
            $testPath = $case['testPath'] ?: 'Unmapped feature scenario';
            $scenario = str_replace(' -> ', ' → ', $case['label']);

            $this->line(sprintf(
                '<%s> %s </> %s <fg=gray>→ %s</>',
                $style,
                $status,
                $this->formatTestPath($testPath),
                $scenario,
            ));

            foreach ($case['steps'] as $step) {
                [$icon, $iconStyle] = match ($step['status']) {
                    'passed' => ['✓', 'fg=green'],
                    'failed' => ['⨯', 'fg=red'],
                    default => ['!', 'fg=yellow'],
                };

                $this->line(sprintf('  <%s>%s</> %s', $iconStyle, $icon, $step['label']));
            }

            $this->renderExamplesTables($case['examples'], $case['examplesStatus']);

            if ($case['status'] === 'failed' && $case['message'] !== null) {
                $failures[] = $case;
            } elseif ($case['status'] === 'skipped' && $case['message'] !== null) {
                $this->newLine();
                foreach (explode("\n", $case['message']) as $line) {
                    $this->line('    '.$line);
                }
            }

            $this->newLine();
        }

        if ($failures !== []) {
            $this->line('<fg=red>'.str_repeat('─', 76).'</>');

            foreach ($failures as $failure) {
                $testPath = $failure['testPath'] ?: 'Unmapped feature scenario';
                $scenario = str_replace(' -> ', ' → ', $failure['label']);

                $this->line(sprintf(
                    '<fg=white;bg=red;options=bold> FAILED </> <options=bold>%s</> <fg=gray>→ %s</>',
                    $this->formatTestPath($testPath),
                    $scenario,
                ));

                foreach (explode("\n", $failure['message']) as $line) {
                    $this->line('  '.$line);
                }

                $this->newLine();
            }
        }

        $parts = [];
        if ($result->errors !== []) {
            $parts[] = sprintf('<fg=red;options=bold>%d failed</>', count($result->errors));
        }
        if ($result->successes !== []) {
            $parts[] = sprintf('<fg=green;options=bold>%d covered</>', count($result->successes));
        }
        if ($result->skipped !== []) {
            $parts[] = sprintf('<fg=yellow;options=bold>%d skipped</>', count($result->skipped));
        }

        $this->line(sprintf(
            '  <options=bold>Scenarios:</>  %s',
            implode(', ', $parts),
        ));
    }

    private function enableAnsiColors(): void
    {
        if ($this->input->hasParameterOption('--no-ansi', true)) {
            return;
        }

        $this->output->setDecorated(true);
    }

    private function formatTestPath(string $testPath): string
    {
        $normalized = str_replace('\\', '/', $testPath);
        $testsPosition = strrpos($normalized, '/tests/');

        if ($testsPosition !== false) {
            $normalized = substr($normalized, $testsPosition + 1);
        }

        if (str_starts_with($normalized, 'tests/')) {
            $normalized = 'Tests/'.substr($normalized, strlen('tests/'));
        }

        return str_replace('/', '\\', preg_replace('/\.php$/', '', $normalized) ?? $normalized);
    }

    /**
     * @param  list<array{block:int,label:string|null,values:array<string,string>}>  $examples
     */
    private function renderExamplesTables(array $examples, ?string $status): void
    {
        $blocks = [];
        foreach ($examples as $example) {
            $blocks[$example['block']][] = $example;
        }

        foreach ($blocks as $rows) {
            $heading = $rows[0]['label'] === null ? 'Examples:' : 'Examples: '.$rows[0]['label'];
            if ($status === 'ignored') {
                $heading = rtrim($heading, ':').' (validation ignored):';
            } elseif ($status === 'disabled') {
                $heading = rtrim($heading, ':').' (validation disabled):';
            }
            $headers = array_map($this->formatExampleCell(...), array_keys($rows[0]['values']));
            $tableRows = array_map(
                fn (array $row): array => array_map($this->formatExampleCell(...), array_values($row['values'])),
                $rows,
            );
            $widths = [];

            foreach ($headers as $column => $header) {
                $widths[$column] = $this->displayWidth($header);
                foreach ($tableRows as $tableRow) {
                    $widths[$column] = max($widths[$column], $this->displayWidth($tableRow[$column]));
                }
            }

            [$icon, $style] = match ($status) {
                'failed' => ['⨯', 'fg=red'],
                'ignored', 'disabled', 'skipped' => ['!', 'fg=yellow'],
                default => ['✓', 'fg=green'],
            };

            $this->line(sprintf('  <%s>%s</> %s', $style, $icon, $heading));
            $this->line('    '.$this->formatExamplesRow($headers, $widths));
            foreach ($tableRows as $tableRow) {
                $this->line('    '.$this->formatExamplesRow($tableRow, $widths));
            }
        }
    }

    /**
     * @param  list<string>  $cells
     * @param  list<int>  $widths
     */
    private function formatExamplesRow(array $cells, array $widths): string
    {
        $padded = array_map(
            fn (string $cell, int $column): string => $cell.str_repeat(' ', $widths[$column] - $this->displayWidth($cell)),
            $cells,
            array_keys($cells),
        );

        return '| '.implode(' | ', $padded).' |';
    }

    private function displayWidth(string $value): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($value) : strlen($value);
    }

    private function formatExampleCell(string $value): string
    {
        return str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
    }
}
