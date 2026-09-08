<?php

namespace Gherkish\Console;

use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\FeatureParity\FeatureParityConfigurationException;
use Gherkish\FeatureParity\FeatureParityResult;
use Gherkish\FeatureParity\StagedFileResolver;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class CheckFeaturesCommand extends Command
{
    protected $signature = 'gherkish:check '
        .'{--dir= : Limit the check to features and Pest tests inside this directory}'
        .'{--feature= : Only check a specific feature file}'
        .'{--file= : Alias for --feature}'
        .'{--f= : Alias for --feature}'
        .'{--staged : Only check feature and Pest test files staged in Git}'
        .'{--check-outline-datasets : Validate Scenario Outline datasets against their Examples tables}'
        .'{--check-unmapped-tests : Validate that every Pest test maps to a Gherkin scenario}'
        .'{--strict : Require every Scenario and Scenario Outline to contain Given, When, and Then phases}'
        .'{--descriptive : Show every scenario, step, and Examples table}'
        .'{--snapshot= : Write the coverage snapshot JSON to the given path}';

    protected $description = 'Verify that every feature scenario has a matching Pest implementation.';

    /**
     * @var array<string, string|null>
     */
    private array $envBackup = [];

    private bool $selectionDirty = false;

    public function handle(StagedFileResolver $stagedFileResolver): int
    {
        $startedAt = microtime(true);
        $this->enableAnsiColors();
        $this->captureEnvState();

        $result = null;
        $exitCode = self::SUCCESS;

        try {
            $this->applyRuntimeOverrides();

            if ($this->option('staged')) {
                $this->assertStagedSelectionIsCompatible();
                FeatureParityChecker::selectStagedPaths($stagedFileResolver->resolve(base_path()));
            }

            $result = FeatureParityChecker::run();
            FeatureParityChecker::maybeWriteSnapshot();
        } catch (FeatureParityConfigurationException $exception) {
            $this->error($this->escape($exception->getMessage()));
            $exitCode = self::FAILURE;
        }

        if ($result instanceof FeatureParityResult) {
            $this->renderResult($result, microtime(true) - $startedAt);
            $exitCode = $result->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        $this->restoreEnvState();

        return $exitCode;
    }

    private function assertStagedSelectionIsCompatible(): void
    {
        foreach (['FEATURE_PARITY_DIR', 'FEATURE_PARITY_FILE', 'FEATURE_PARITY_FEATURE'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                throw new FeatureParityConfigurationException(
                    'The --staged option cannot be combined with --dir, --feature, --file, or --f.'
                );
            }
        }
    }

    private function captureEnvState(): void
    {
        foreach (['FEATURE_PARITY_DIR', 'FEATURE_PARITY_FILE', 'FEATURE_PARITY_FEATURE', 'FEATURE_PARITY_CHECK_OUTLINE_DATASETS', 'FEATURE_PARITY_CHECK_UNMAPPED_TESTS', 'FEATURE_PARITY_STRICT', 'FEATURE_PARITY_SNAPSHOT'] as $key) {
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

        if ($this->option('check-unmapped-tests')) {
            $this->setEnv('FEATURE_PARITY_CHECK_UNMAPPED_TESTS', '1');
        }

        if ($this->option('strict')) {
            $this->setEnv('FEATURE_PARITY_STRICT', '1');
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

    private function renderResult(FeatureParityResult $result, float $duration): void
    {
        if ($this->option('descriptive')) {
            $this->renderDescriptiveResult($result);
        } else {
            $this->renderCompactResult($result);
        }

        $this->renderSummary($result, $duration);
    }

    private function renderCompactResult(FeatureParityResult $result): void
    {
        $this->newLine();
        $progress = array_map(
            static fn (array $case): string => match ($case['status']) {
                'passed' => '<fg=green>.</>',
                'failed' => '<fg=red;options=bold>F</>',
                default => '<fg=yellow;options=bold>S</>',
            },
            $result->cases,
        );

        foreach (array_chunk($progress, 60) as $line) {
            $this->line('  '.implode('', $line));
        }

        $this->newLine();
        $this->renderFailures(array_values(array_filter(
            $result->cases,
            static fn (array $case): bool => $case['status'] === 'failed' && $case['message'] !== null,
        )));
    }

    private function renderDescriptiveResult(FeatureParityResult $result): void
    {
        $this->newLine();
        $failures = [];

        foreach ($result->backgrounds as $background) {
            $this->line(sprintf(
                '<fg=black;bg=blue;options=bold> BACKGROUND </> %s <fg=gray>→ %s</>',
                $this->escape($this->formatTestPath($background['testPath'])),
                $this->escape(str_replace(' -> ', ' → ', $background['label'])),
            ));

            foreach ($background['steps'] as $step) {
                [$icon, $style] = $step['status'] === 'passed'
                    ? ['✓', 'fg=green']
                    : ['⨯', 'fg=red'];
                $this->line(sprintf('  <%s>%s</> %s', $style, $icon, $this->escape($step['label'])));
            }

            $this->newLine();
        }

        foreach ($result->cases as $case) {
            $status = match ($case['status']) {
                'passed' => 'COVERED',
                'failed' => 'MISSING',
                default => 'WARN',
            };
            $style = match ($status) {
                'COVERED' => 'fg=black;bg=green;options=bold',
                'MISSING' => 'fg=white;bg=red;options=bold',
                default => 'fg=black;bg=yellow;options=bold',
            };
            $testPath = $case['testPath'] ?: 'Unmapped feature scenario';
            $scenario = str_replace(' -> ', ' → ', $case['label']);

            $this->line(sprintf(
                '<%s> %s </> %s <fg=gray>→ %s</>',
                $style,
                $status,
                $this->escape($this->formatTestPath($testPath)),
                $this->escape($scenario),
            ));

            foreach ($case['steps'] as $step) {
                [$icon, $iconStyle] = match ($step['status']) {
                    'passed' => ['✓', 'fg=green'],
                    'failed' => ['⨯', 'fg=red'],
                    default => ['!', 'fg=yellow'],
                };

                $this->line(sprintf('  <%s>%s</> %s', $iconStyle, $icon, $this->escape($step['label'])));
            }

            $this->renderExamplesTables($case['examples'], $case['examplesStatus']);

            if ($case['status'] === 'failed' && $case['message'] !== null) {
                $failures[] = $case;
            } elseif ($case['status'] === 'skipped' && $case['message'] !== null) {
                $this->newLine();
                foreach (explode("\n", $case['message']) as $line) {
                    $this->line('    '.$this->escape($line));
                }
            }

            $this->newLine();
        }

        $this->renderFailures($failures);
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    private function renderFailures(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $this->line('<fg=red>'.str_repeat('─', 76).'</>');

        foreach ($failures as $failure) {
            $testPath = $failure['testPath'] ?: 'Unmapped feature scenario';
            $scenario = str_replace(' -> ', ' → ', $failure['label']);

            $this->line(sprintf(
                '<fg=white;bg=red;options=bold> MISSING </> <options=bold>%s</> <fg=gray>→ %s</>',
                $this->escape($this->formatTestPath($testPath)),
                $this->escape($scenario),
            ));

            foreach (explode("\n", $failure['message']) as $line) {
                $this->line('  '.$this->escape($line));
            }

            $this->newLine();
        }
    }

    private function renderSummary(FeatureParityResult $result, float $duration): void
    {
        $parts = [];
        $failed = count($result->errors) - count($result->unmappedTests);
        $covered = count($result->successes);
        $skipped = count($result->skipped);

        if ($failed > 0) {
            $parts[] = sprintf('<fg=red;options=bold>%d missing</>', $failed);
        }
        if ($covered > 0) {
            $parts[] = sprintf('<fg=green;options=bold>%d covered</>', $covered);
        }
        if ($skipped > 0) {
            $parts[] = sprintf('<fg=yellow;options=bold>%d skipped</>', $skipped);
        }

        if ($parts !== []) {
            $totalCases = array_sum(array_map(
                static fn (array $case): int => count($case['steps']),
                $result->cases,
            )) + array_sum(array_map(
                static fn (array $background): int => count($background['steps']),
                $result->backgrounds,
            ));
            $caseLabel = $totalCases === 1 ? 'case' : 'cases';
            $this->line(sprintf(
                '  <options=bold>Scenarios:</>  %s <fg=gray>(%d %s)</>',
                implode(', ', $parts),
                $totalCases,
                $caseLabel,
            ));
        }

        if ($result->unmappedTests !== []) {
            $this->line(sprintf(
                '  <options=bold>Tests:</>      <fg=red;options=bold>%d unmapped</>',
                count($result->unmappedTests),
            ));
        }

        $this->line(sprintf(
            '  <options=bold>Duration:</>   <options=bold>%.2fs</>',
            $duration,
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

            $this->line(sprintf('  <%s>%s</> %s', $style, $icon, $this->escape($heading)));
            $this->line('    '.$this->escape($this->formatExamplesRow($headers, $widths)));
            foreach ($tableRows as $tableRow) {
                $this->line('    '.$this->escape($this->formatExamplesRow($tableRow, $widths)));
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

    private function escape(string $value): string
    {
        return OutputFormatter::escape($value);
    }
}
