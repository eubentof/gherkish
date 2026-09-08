<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FeatureParityChecker
{
    private static ?FeatureParitySelection $selection = null;

    public static function run(): FeatureParityResult
    {
        $selection = self::selection();
        $result = new FeatureParityResult;

        if ($selection->rawDir !== null && $selection->dir === null) {
            throw new FeatureParityConfigurationException(sprintf(
                'Could not locate directory "%s" for --dir filter.',
                $selection->rawDir
            ));
        }

        if ($selection->rawFile !== null && $selection->file === null) {
            throw new FeatureParityConfigurationException(sprintf(
                'Could not locate feature file "%s" for --feature filter.',
                $selection->rawFile
            ));
        }

        $featurePaths = self::selectedFeaturePaths();

        if (empty($featurePaths)) {
            $target = $selection->file ?? $selection->dir ?? 'tests directory';
            $result->addSkipped(
                'feature parity selection',
                sprintf('No Gherkin scenarios found for selection "%s".', $target)
            );

            return $result;
        }

        foreach ($featurePaths as $featurePath) {
            $feature = self::parseFeature($featurePath);
            $featureTestPaths = self::resolveFeatureTestPaths($feature);

            if (empty($feature->scenarios)) {
                continue;
            }

            foreach ($feature->scenarios as $scenario) {
                if (empty($scenario->steps)) {
                    continue;
                }

                $scenarioLabel = sprintf(
                    '%s -> %s',
                    $feature->title ?: $feature->basename,
                    $scenario->title
                );
                $testPaths = $featureTestPaths ?? [self::pairTestPath($feature->path)];
                $testPath = self::relativeTestPath($testPaths[0]);

                try {
                    $matchedTest = self::assertScenarioParity($feature, $scenario, $featureTestPaths);
                    $result->addSuccess(
                        $scenarioLabel,
                        self::relativeTestPath($matchedTest->filePath),
                        self::scenarioStepCases($scenario, $testPaths, 'passed'),
                        $scenario->examples,
                        ! self::shouldCheckOutlineDatasets()
                            ? 'disabled'
                            : ($matchedTest->ignoreExamples ? 'ignored' : 'passed'),
                    );
                } catch (FeatureParitySkippedException $exception) {
                    $result->addSkipped(
                        $scenarioLabel,
                        $exception->getMessage(),
                        $testPath,
                        self::scenarioStepCases($scenario, $testPaths, 'skipped'),
                        $scenario->examples,
                    );
                } catch (ScenarioOutlineDatasetException $exception) {
                    $result->addError(
                        $scenarioLabel,
                        $exception->getMessage(),
                        $testPath,
                        self::scenarioStepCases($scenario, $testPaths, 'passed'),
                        $scenario->examples,
                        'failed',
                    );
                } catch (FeatureParityException $exception) {
                    $result->addError(
                        $scenarioLabel,
                        $exception->getMessage(),
                        $testPath,
                        self::scenarioStepCases($scenario, $testPaths),
                        $scenario->examples,
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{status:string,label:string}>
     */
    private static function scenarioStepCases(
        ScenarioDoc $scenario,
        ?array $testPaths,
        ?string $forcedStatus = null,
    ): array {
        $implementedSignatures = [];

        if ($forcedStatus === null) {
            $existingTestPaths = array_values(array_filter($testPaths ?? [], 'is_file'));
            foreach ($existingTestPaths as $path) {
                foreach (self::findMatchingTests($scenario, self::parsePestFile($path)->tests) as $test) {
                    $unimplementedLines = array_column($test->unimplementedStepComments, 'line');
                    foreach ($test->stepComments as $step) {
                        if (! in_array($step->line, $unimplementedLines, true)) {
                            $implementedSignatures[self::normalizeStepSignature($step->keyword, $step->text)] = true;
                        }
                    }
                }
            }
        }

        return array_map(
            static function (StepDoc $step) use ($forcedStatus, $implementedSignatures): array {
                $signature = self::normalizeStepSignature($step->keyword, $step->text);

                return [
                    'status' => $forcedStatus ?? (isset($implementedSignatures[$signature]) ? 'passed' : 'failed'),
                    'label' => sprintf('%s %s', $step->keyword, $step->text),
                ];
            },
            $scenario->steps,
        );
    }

    public static function resetSelection(): void
    {
        self::$selection = null;
    }

    public static function snapshot(?string $outputPath = null): array
    {
        $snapshot = [];
        $featurePaths = self::selectedFeaturePaths();

        foreach ($featurePaths as $featurePath) {
            $feature = self::parseFeature($featurePath);
            if (empty($feature->scenarios)) {
                continue;
            }

            $relativeFeature = self::relativeTestPath($featurePath);
            $testPaths = self::resolveFeatureTestPaths($feature) ?? [self::pairTestPath($featurePath)];
            $existingTestPaths = array_values(array_filter($testPaths, 'is_file'));
            $tests = [];
            foreach ($existingTestPaths as $path) {
                $pestFile = self::parsePestFile($path);
                $tests = array_merge($tests, $pestFile->tests);
            }

            $scenariosPayload = [];
            foreach ($feature->scenarios as $scenario) {
                $matching = self::findMatchingTests($scenario, $tests);
                $exact = self::findExactStepMatch($scenario, $matching);
                $exactTest = $exact['test'] ?? null;
                $mapping = $exact['mapping'] ?? [];
                $stepsPayload = [];
                $coveredSteps = [];
                $missingSteps = [];

                foreach ($scenario->steps as $index => $step) {
                    $expectedSignature = self::normalizeStepSignature($step->keyword, $step->text);
                    $found = $exactTest !== null
                        ? array_key_exists($index, $mapping)
                        : self::hasStepComment($matching, $expectedSignature);

                    $stepLabel = sprintf('%s %s', $step->keyword, $step->text);
                    $stepsPayload[$stepLabel] = $found;

                    if ($found) {
                        $coveredSteps[] = $stepLabel;
                    } else {
                        $missingSteps[] = $stepLabel;
                    }
                }

                $scenariosPayload[$scenario->title] = [
                    'steps' => $stepsPayload,
                    'coverage' => [
                        'covered' => $coveredSteps,
                        'missing' => $missingSteps,
                        'coveredCount' => count($coveredSteps),
                        'missingCount' => count($missingSteps),
                        'total' => count($stepsPayload),
                    ],
                ];
            }

            $testsPayload = [];
            foreach ($tests as $test) {
                $testsPayload[$test->name] = array_map(
                    static fn (StepDoc $step): string => sprintf('%s %s', $step->keyword, $step->text),
                    $test->stepComments
                );
            }

            $snapshot[$relativeFeature] = [
                'feature' => $feature->title ?: $feature->basename,
                'scenarios' => $scenariosPayload,
                'tests' => $testsPayload,
            ];
        }

        if ($outputPath !== null) {
            file_put_contents(
                $outputPath,
                json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $snapshot;
    }

    public static function maybeWriteSnapshot(): void
    {
        $target = getenv('FEATURE_PARITY_SNAPSHOT') ?: null;
        if ($target === null || $target === '') {
            return;
        }

        $resolved = self::resolvePathInput($target, mustBeFile: false);
        $path = $resolved ?? (self::projectBasePath().DIRECTORY_SEPARATOR.ltrim($target, DIRECTORY_SEPARATOR));

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        self::snapshot($path);
    }

    private static function selection(bool $reset = false): FeatureParitySelection
    {
        if (self::$selection !== null && ! $reset) {
            return self::$selection;
        }

        $rawDir = getenv('FEATURE_PARITY_DIR') ?: null;
        $rawFile = getenv('FEATURE_PARITY_FILE') ?: (getenv('FEATURE_PARITY_FEATURE') ?: null);

        $argv = $_SERVER['argv'] ?? [];
        $cliDir = self::extractCliOption($argv, '--dir');
        $cliFile = self::extractCliOption($argv, '--feature') ?? self::extractCliOption($argv, '--file');

        if ($cliDir !== null) {
            $rawDir = $cliDir;
        }

        if ($cliFile !== null) {
            $rawFile = $cliFile;
        }

        $dir = self::resolvePathInput($rawDir, mustBeFile: false);
        $file = self::resolvePathInput($rawFile, mustBeFile: true);

        return self::$selection = new FeatureParitySelection($dir, $file, $rawDir, $rawFile);
    }

    private static function extractCliOption(array $argv, string $option): ?string
    {
        foreach ($argv as $index => $arg) {
            if (str_starts_with($arg, $option.'=')) {
                $value = substr($arg, strlen($option) + 1);

                return $value !== '' ? $value : null;
            }

            if ($arg === $option && isset($argv[$index + 1])) {
                $value = $argv[$index + 1];
                if ($value !== '' && ! str_starts_with($value, '--')) {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function resolvePathInput(?string $value, bool $mustBeFile): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $candidates = [];
        if (str_starts_with($value, DIRECTORY_SEPARATOR)) {
            $candidates[] = $value;
        }

        $project = self::projectBasePath();
        $candidates[] = $project.DIRECTORY_SEPARATOR.ltrim($value, DIRECTORY_SEPARATOR);
        $candidates[] = self::testsBasePath().DIRECTORY_SEPARATOR.ltrim($value, DIRECTORY_SEPARATOR);

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }

            if ($mustBeFile && is_file($real)) {
                return $real;
            }

            if (! $mustBeFile && is_dir($real)) {
                return rtrim($real, DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    private static function projectBasePath(): string
    {
        return dirname(self::testsBasePath());
    }

    private static function selectedFeaturePaths(): array
    {
        $selection = self::selection();

        if ($selection->file !== null) {
            return is_file($selection->file) ? [$selection->file] : [];
        }

        if ($selection->dir !== null) {
            return self::findFeatureFiles($selection->dir);
        }

        $paths = array_merge(
            self::findFeatureFiles(self::testsBasePath()),
            self::findFeatureFiles(self::appBasePath()),
        );

        sort($paths);

        return array_values(array_unique($paths));
    }

    private static function testsBasePath(): string
    {
        if (function_exists('base_path')) {
            $path = base_path('tests');
        } else {
            $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'tests';
        }

        return realpath($path) ?: $path;
    }

    private static function appBasePath(): string
    {
        if (function_exists('base_path')) {
            $path = base_path('app');
        } else {
            $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
        }

        return realpath($path) ?: $path;
    }

    /**
     * @return string[]
     */
    private static function findFeatureFiles(?string $base = null): array
    {
        $base ??= self::testsBasePath();

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        $paths = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with($path, '.feature')) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    private static function pairTestPath(string $featurePath): string
    {
        return preg_replace('/\.feature$/', 'Test.php', $featurePath) ?? $featurePath.'Test.php';
    }

    private static function parseFeature(string $path): FeatureDoc
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $outlinesByLine = [];

        try {
            foreach ((new ExampleDatasetResolver)->outlines($path) as $outline) {
                $outlinesByLine[$outline->line] = $outline;
            }
        } catch (ExamplesException) {
            // Example validation remains the responsibility of Gherkish::examples().
        }

        $featureTitle = null;
        $scenarios = [];
        $currentScenario = null;
        $collectingExamples = false;
        $collectingTests = false;
        $tests = [];
        $hasTestsSection = false;
        $inBackground = false;

        $flushScenario = function () use (&$currentScenario, &$scenarios): void {
            if ($currentScenario === null) {
                return;
            }

            $scenarios[] = new ScenarioDoc(
                $currentScenario['title'],
                $currentScenario['steps'],
                $currentScenario['line'] ?? 1,
                $currentScenario['examples'],
                $currentScenario['isOutline'],
            );
            $currentScenario = null;
        };

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '') {
                $collectingExamples = false;
                $collectingTests = false;

                continue;
            }

            if (preg_match('/^Feature:\s*(.+)?$/i', $trimmed, $match)) {
                if ($featureTitle === null && isset($match[1])) {
                    $featureTitle = trim($match[1]);
                }

                $inBackground = false;

                continue;
            }

            if (preg_match('/^@tests:\s*$/i', $trimmed)) {
                $collectingTests = true;
                $hasTestsSection = true;

                continue;
            }

            if ($collectingTests) {
                if (preg_match('/^-\s*(.+)$/', $trimmed, $match)) {
                    $tests[] = trim($match[1]);

                    continue;
                }

                $collectingTests = false;
            }

            if (preg_match('/^Background:/i', $trimmed)) {
                $inBackground = true;

                continue;
            }

            if (preg_match('/^Scenario(?P<outline> Outline)?:\s*(?P<title>.+)$/i', $trimmed, $match)) {
                $collectingExamples = false;
                $flushScenario();

                $examples = [];
                if (isset($outlinesByLine[$lineNumber])) {
                    foreach ($outlinesByLine[$lineNumber]->examples as $blockIndex => $block) {
                        foreach ($block->rows as $row) {
                            $examples[] = [
                                'block' => $blockIndex,
                                'label' => $block->label,
                                'values' => $row,
                            ];
                        }
                    }
                }

                $currentScenario = [
                    'title' => trim($match['title']),
                    'steps' => [],
                    'line' => $lineNumber,
                    'examples' => $examples,
                    'isOutline' => ($match['outline'] ?? '') !== '',
                ];

                $inBackground = false;

                continue;
            }

            if (preg_match('/^Examples?:/i', $trimmed)) {
                $collectingExamples = true;

                continue;
            }

            if ($collectingExamples && str_starts_with(ltrim($line), '|')) {
                continue;
            }

            if (! preg_match('/^(Given|When|Then|And|But)\b(.*)$/i', ltrim($line), $match)) {
                continue;
            }

            if ($inBackground || $currentScenario === null) {
                continue;
            }

            $keyword = ucfirst(strtolower($match[1]));
            $text = trim($match[2]);
            $currentScenario['steps'][] = new StepDoc($keyword, $text, $lineNumber);
        }

        $flushScenario();

        return new FeatureDoc(
            $path,
            basename($path, '.feature'),
            dirname($path),
            $scenarios,
            $featureTitle,
            $tests,
            $hasTestsSection,
        );
    }

    private static function assertScenarioParity(FeatureDoc $feature, ScenarioDoc $scenario, ?array $testPaths = null): TestBlock
    {
        $relativeFeature = self::relativeTestPath($feature->path);
        if (empty($scenario->steps)) {
            throw new FeatureParitySkippedException(sprintf('Scenario "%s" in %s has no steps to verify.', $scenario->title, $relativeFeature));
        }

        $scenarioLocation = sprintf('%s:%d', $relativeFeature, $scenario->line);
        $usesListedTests = $testPaths !== null;
        $testPathsToUse = $testPaths ?? [self::pairTestPath($feature->path)];
        $existingTestPaths = array_values(array_filter($testPathsToUse, 'is_file'));

        if (empty($existingTestPaths)) {
            if ($usesListedTests) {
                throw new FeatureParityException(sprintf(
                    'Failed asserting that scenario "%s" (%s) is covered: no test file was found for the feature in Tests section.',
                    $scenario->title,
                    $scenarioLocation
                ));
            }

            $testPath = $testPathsToUse[0] ?? self::pairTestPath($feature->path);
            $relativeTest = self::relativeTestPath($testPath);

            throw new FeatureParityException(sprintf(
                'Failed asserting that scenario "%s" (%s) is covered: feature has no paired test file %s',
                $scenario->title,
                $scenarioLocation,
                $relativeTest
            ));
        }

        $tests = [];
        foreach ($existingTestPaths as $path) {
            $pestFile = self::parsePestFile($path);
            $tests = array_merge($tests, $pestFile->tests);
        }

        $matchingTests = self::findMatchingTests($scenario, $tests);
        $testReference = $usesListedTests
            ? self::formatTestPathsList($existingTestPaths)
            : self::relativeTestPath($existingTestPaths[0]);

        if (empty($matchingTests)) {
            throw new FeatureParityException(sprintf(
                "Failed asserting that scenario \"%s\" (%s) is covered: no matching test() description in %s.\nSequences (✔ documented | ✘ missing):\n%s\nTests inspected: %s",
                $scenario->title,
                $scenarioLocation,
                $testReference,
                self::formatSequenceCoverage($feature, $scenario, $matchingTests),
                self::formatTestNamesList($tests)
            ));
        }

        $scenarioStepsBySignature = [];
        foreach ($scenario->steps as $step) {
            $scenarioStepsBySignature[self::normalizeStepSignature($step->keyword, $step->text)] = $step;
        }

        $unimplementedStepComments = [];
        foreach ($matchingTests as $test) {
            foreach ($test->unimplementedStepComments as $step) {
                $signature = self::normalizeStepSignature($step->keyword, $step->text);
                if (! isset($scenarioStepsBySignature[$signature])) {
                    continue;
                }

                $unimplementedStepComments[] = [$test, $step, $scenarioStepsBySignature[$signature]];
            }
        }

        if ($unimplementedStepComments !== []) {
            $violations = array_map(
                static fn (array $violation): string => sprintf(
                    "- %s %s\n  Feature step: %s:%d\n  Pest docblock: %s:%d",
                    $violation[1]->keyword,
                    $violation[1]->text,
                    $relativeFeature,
                    $violation[2]->line,
                    self::relativeTestPath($violation[0]->filePath),
                    $violation[1]->line,
                ),
                $unimplementedStepComments,
            );

            throw new FeatureParityException(sprintf(
                "Scenario \"%s\" has Pest step docblocks without executable PHP code directly below them.\n\n%s",
                $scenario->title,
                implode("\n", $violations),
            ));
        }

        $matched = self::findExactStepMatch($scenario, $matchingTests);

        if ($matched === null) {
            $primaryTest = self::primaryTestReference($matchingTests) ?? $testReference;
            throw new FeatureParityException(sprintf(
                "Failed asserting that scenario \"%s\" (%s) is covered: Pest tests in %s do not cover all steps.\nSequences (✔ documented | ✘ missing):\n%s\n",
                $scenario->title,
                $scenarioLocation,
                $primaryTest,
                self::formatSequenceCoverage($feature, $scenario, $matchingTests),
            ));
        }

        self::assertScenarioOutlineDataset($feature, $scenario, $matched['test']);

        return $matched['test'];
    }

    private static function assertScenarioOutlineDataset(
        FeatureDoc $feature,
        ScenarioDoc $scenario,
        TestBlock $test,
    ): void {
        if (! $scenario->isOutline || ! self::shouldCheckOutlineDatasets() || $test->ignoreExamples) {
            return;
        }

        $withArguments = self::extractWithArguments($test->body);
        $expectedValues = [];
        $blocks = [];
        foreach ($scenario->examples as $example) {
            $blocks[$example['block']]['label'] = $example['label'];
            $blocks[$example['block']]['rows'][] = $example['values'];
            array_push($expectedValues, ...array_values($example['values']));
        }

        if ($withArguments !== [] && self::usesMatchingGherkishExamples($withArguments, $blocks)) {
            return;
        }

        foreach ($withArguments as $argument) {
            $literalValues = self::literalDatasetValues($argument);
            if ($literalValues !== null && $literalValues === $expectedValues) {
                return;
            }
        }

        $featureLocation = sprintf('%s:%d', self::relativeTestPath($feature->path), $scenario->line);
        $testLocation = sprintf('%s:%d', self::relativeTestPath($test->filePath), $test->line);

        throw new ScenarioOutlineDatasetException(sprintf(
            "Scenario Outline \"%s\" must map its Examples table to the Pest test dataset.\n\nUse ->with(Gherkish::examples()), labeled Gherkish::examples(...) calls, or a literal array containing the exact example values.\nFeature outline: %s\nPest test: %s",
            $scenario->title,
            $featureLocation,
            $testLocation,
        ));
    }

    /**
     * @return list<string>
     */
    private static function extractWithArguments(string $body): array
    {
        $arguments = [];
        $offset = 0;

        while (preg_match('/->with\s*\(/', $body, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $opening = $match[0][1] + strlen($match[0][0]) - 1;
            $closing = self::matchingDelimiterOffset($body, $opening, '(', ')');
            if ($closing === null) {
                break;
            }

            $arguments[] = trim(substr($body, $opening + 1, $closing - $opening - 1));
            $offset = $closing + 1;
        }

        return $arguments;
    }

    private static function shouldCheckOutlineDatasets(): bool
    {
        return filter_var(
            getenv('FEATURE_PARITY_CHECK_OUTLINE_DATASETS') ?: false,
            FILTER_VALIDATE_BOOL,
        );
    }

    /**
     * @param  array<int, array{label:string|null,rows:list<array<string,string>>}>  $blocks
     */
    private static function usesMatchingGherkishExamples(array $withArguments, array $blocks): bool
    {
        $calls = [];
        $expression = implode("\n", $withArguments);
        $offset = 0;

        while (preg_match(
            '/(?:\\\\?Gherkish\\\\)?Gherkish::examples\s*\(/',
            $expression,
            $match,
            PREG_OFFSET_CAPTURE,
            $offset,
        )) {
            $opening = $match[0][1] + strlen($match[0][0]) - 1;
            $closing = self::matchingDelimiterOffset($expression, $opening, '(', ')');
            if ($closing === null) {
                return false;
            }

            $labels = self::literalStringArguments(substr($expression, $opening + 1, $closing - $opening - 1));
            if ($labels === null) {
                return false;
            }

            if ($labels === []) {
                $calls[] = null;
            } else {
                array_push($calls, ...$labels);
            }

            $offset = $closing + 1;
        }

        if ($calls === [] || $blocks === []) {
            return false;
        }

        $expectedLabels = array_values(array_map(
            static fn (array $block): ?string => $block['label'],
            $blocks,
        ));

        if (count($expectedLabels) === 1) {
            return count($calls) === 1
                && ($calls[0] === null || $calls[0] === $expectedLabels[0]);
        }

        if (in_array(null, $expectedLabels, true) || in_array(null, $calls, true)) {
            return false;
        }

        sort($expectedLabels);
        sort($calls);

        return $calls === $expectedLabels;
    }

    /**
     * @return list<string|null>|null
     */
    private static function literalStringArguments(string $arguments): ?array
    {
        if (trim($arguments) === '') {
            return [];
        }

        $labels = [];
        $expectValue = true;

        foreach (token_get_all('<?php '.$arguments) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true)) {
                    continue;
                }

                if ($expectValue && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $quote = $token[1][0];
                    $value = substr($token[1], 1, -1);
                    $labels[] = $quote === "'"
                        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
                        : stripcslashes($value);
                    $expectValue = false;

                    continue;
                }

                if ($expectValue && $token[0] === T_STRING && strtolower($token[1]) === 'null') {
                    $labels[] = null;
                    $expectValue = false;

                    continue;
                }

                return null;
            }

            if ($token === ',' && ! $expectValue) {
                $expectValue = true;

                continue;
            }

            return null;
        }

        return $expectValue ? null : $labels;
    }

    /**
     * @return list<string>|null
     */
    private static function literalDatasetValues(string $expression): ?array
    {
        $tokens = token_get_all('<?php '.$expression);
        $values = [];

        foreach ($tokens as $index => $token) {
            if (is_string($token)) {
                if (! in_array($token, ['[', ']', '(', ')', ',', '='], true) && $token !== '>') {
                    return null;
                }

                continue;
            }

            [$type, $text] = $token;
            if (in_array($type, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ARRAY, T_DOUBLE_ARROW], true)) {
                continue;
            }

            if (! in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)) {
                return null;
            }

            if (self::nextSignificantTokenIsDoubleArrow($tokens, $index)) {
                continue;
            }

            $values[] = $type === T_CONSTANT_ENCAPSED_STRING
                ? self::decodePhpStringLiteral($text)
                : $text;
        }

        return $values;
    }

    private static function nextSignificantTokenIsDoubleArrow(array $tokens, int $index): bool
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_DOUBLE_ARROW;
        }

        return false;
    }

    private static function decodePhpStringLiteral(string $literal): string
    {
        $quote = $literal[0];
        $value = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
            : stripcslashes($value);
    }

    private static function matchingDelimiterOffset(
        string $text,
        int $openingOffset,
        string $opening,
        string $closing,
    ): ?int {
        $depth = 0;
        $string = null;
        $escaped = false;
        $length = strlen($text);

        for ($index = $openingOffset; $index < $length; $index++) {
            $character = $text[$index];

            if ($string !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $string) {
                    $string = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $string = $character;

                continue;
            }

            if ($character === $opening) {
                $depth++;
            } elseif ($character === $closing && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function parsePestFile(string $path): PestFile
    {
        $content = file_get_contents($path) ?: '';
        $tests = self::extractTestBlocks($content, $path);

        return new PestFile($path, $tests);
    }

    /**
     * @return TestBlock[]
     */
    private static function extractTestBlocks(string $content, string $path): array
    {
        // Ensure we only match standalone Pest helpers and not substrings, e.g. the "it" in "visit(".
        $pattern = '~(?<![A-Za-z0-9_])(?P<fn>test|it)\s*\(\s*(["\'])(?P<name>.+?)\2\s*,~is';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tests = [];
        foreach ($matches as $match) {
            $nameLiteral = $match['name'][0];
            $name = stripcslashes($nameLiteral);
            $start = $match[0][1];
            $body = self::extractStatement($content, $start);
            $startLine = self::offsetToLineNumber($content, $start);
            $unimplementedStepComments = [];
            $stepComments = self::extractStepCommentsFromText($body, $startLine, $unimplementedStepComments);
            $tests[] = new TestBlock(
                $name,
                $body,
                $stepComments,
                $path,
                $startLine,
                $unimplementedStepComments,
                self::hasIgnoreExamplesComment($content, $start),
            );
        }

        return $tests;
    }

    private static function hasIgnoreExamplesComment(string $content, int $testOffset): bool
    {
        $beforeTest = substr($content, 0, $testOffset);
        $lines = preg_split('/\R/', $beforeTest) ?: [];

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = trim($lines[$index]);
            if ($line === '') {
                continue;
            }

            return preg_match('~^//\s*@gherkish-ignore-examples\s*$~i', $line) === 1;
        }

        return false;
    }

    private static function extractStatement(string $content, int $start): string
    {
        $length = strlen($content);
        $depth = 0;
        $inString = null;
        $escape = false;
        $inComment = null; // 'line' or 'block'

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($inComment !== null) {
                if ($inComment === 'line') {
                    if ($char === "\n") {
                        $inComment = null;
                    }

                    continue;
                }

                if ($char === '*' && ($content[$i + 1] ?? null) === '/') {
                    $inComment = null;
                    $i++;
                }

                continue;
            }

            if ($inString !== null) {
                if ($escape) {
                    $escape = false;

                    continue;
                }

                if ($char === '\\') {
                    $escape = true;

                    continue;
                }

                if ($char === $inString) {
                    $inString = null;
                }

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $inString = $char;

                continue;
            }

            if ($char === '/' && $inString === null) {
                $next = $content[$i + 1] ?? null;
                if ($next === '/') {
                    $inComment = 'line';
                    $i++;

                    continue;
                }

                if ($next === '*') {
                    $inComment = 'block';
                    $i++;

                    continue;
                }
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }

                continue;
            }

            if ($char === ';' && $depth === 0) {
                return substr($content, $start, $i - $start + 1);
            }
        }

        return substr($content, $start);
    }

    /**
     * @return StepDoc[]
     */
    private static function extractStepCommentsFromText(
        string $text,
        int $initialLine = 1,
        array &$unimplementedStepComments = [],
    ): array {
        $pattern = '/\/\*\*(?P<body>.*?)\*\//s';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $comments = [];
        foreach ($matches as $match) {
            $docBody = $match['body'][0] ?? '';
            if ($docBody === '') {
                continue;
            }

            if (! preg_match('/@(Given|When|Then|And|But)\s+(?P<body>.*)/is', $docBody, $stepMatch)) {
                continue;
            }

            $keyword = ucfirst(strtolower($stepMatch[1] ?? ''));
            $body = self::cleanupStepCommentBody($stepMatch['body'] ?? '');
            if ($body === '') {
                continue;
            }

            $offset = $match[0][1] ?? 0;
            $line = $initialLine + substr_count(substr($text, 0, $offset), "\n");

            $step = new StepDoc($keyword, $body, $line);
            $comments[] = $step;

            $comment = $match[0][0] ?? '';
            if (! self::hasExecutableCodeDirectlyBelow($text, $offset, strlen($comment))) {
                $unimplementedStepComments[] = $step;
            }
        }

        return $comments;
    }

    private static function hasExecutableCodeDirectlyBelow(string $text, int $offset, int $length): bool
    {
        $afterComment = substr($text, $offset + $length);
        if (! preg_match('/^[^\r\n]*(?:\r\n|\r|\n)([^\r\n]*)/', $afterComment, $match)) {
            return false;
        }

        $nextLine = trim($match[1]);

        return $nextLine !== '' && ! preg_match('~^(?://|#|/\*|\*|\*/)~', $nextLine);
    }

    private static function cleanupStepCommentBody(string $body): string
    {
        $body = preg_replace('/\r?\n\s*\*\s*/', ' ', $body);
        $body = str_replace(['*/', '/**'], '', $body);

        return trim($body);
    }

    /**
     * @param  TestBlock[]  $tests
     * @return TestBlock[]
     */
    private static function findMatchingTests(ScenarioDoc $scenario, array $tests): array
    {
        $matches = [];
        foreach ($tests as $test) {
            if (self::matchesTestName($scenario->title, $test->name)) {
                $matches[] = $test;
            }
        }

        return $matches;
    }

    /**
     * @param  TestBlock[]  $tests
     * @return array{test: TestBlock, mapping: array<int,int>}|null
     */
    private static function findExactStepMatch(ScenarioDoc $scenario, array $tests): ?array
    {
        foreach ($tests as $test) {
            $mapping = [];
            if (self::scenarioStepsInTest($scenario, $test, $mapping)) {
                return ['test' => $test, 'mapping' => $mapping];
            }
        }

        return null;
    }

    private static function scenarioStepsInTest(ScenarioDoc $scenario, TestBlock $test, array &$mapping = []): bool
    {
        $scenarioSeq = self::scenarioStepSequence($scenario);
        $testSeq = self::testStepSequence($test);
        $mapping = [];

        $testBuckets = [];
        foreach ($testSeq as $index => $signature) {
            $testBuckets[$signature] ??= [];
            $testBuckets[$signature][] = $index;
        }

        foreach ($scenarioSeq as $scenarioIdx => $expected) {
            if (empty($testBuckets[$expected])) {
                return false;
            }

            $mapping[$scenarioIdx] = array_shift($testBuckets[$expected]);
        }

        return true;
    }

    private static function scenarioStepSequence(ScenarioDoc $scenario): array
    {
        return array_map(
            static fn (StepDoc $step): string => self::normalizeStepSignature($step->keyword, $step->text),
            $scenario->steps
        );
    }

    private static function testStepSequence(TestBlock $test): array
    {
        return array_map(
            static fn (StepDoc $step): string => self::normalizeStepSignature($step->keyword, $step->text),
            $test->stepComments
        );
    }

    private static function formatScenarioStepList(FeatureDoc $feature, ScenarioDoc $scenario): string
    {
        if (empty($scenario->steps)) {
            return '(no steps defined)';
        }

        $lines = [];
        foreach ($scenario->steps as $step) {
            $lines[] = '- '.self::formatStepLabel($feature, $scenario, $step);
        }

        return implode("\n", $lines);
    }

    private static function formatTestStepSummaries(array $tests): string
    {
        if (empty($tests)) {
            return '(no tests found)';
        }

        $lines = [];
        foreach ($tests as $test) {
            if (empty($test->stepComments)) {
                $lines[] = sprintf('test "%s": (no @Given/@When/@Then comments)', $test->name);

                continue;
            }

            $steps = array_map(
                static fn (StepDoc $step): string => sprintf('%s %s', $step->keyword, $step->text),
                $test->stepComments
            );

            $lines[] = sprintf('test "%s": %s', $test->name, implode(' -> ', $steps));
        }

        return implode("\n", $lines);
    }

    private static function formatSingleTestStepSummary(TestBlock $test): string
    {
        $relativePath = self::relativeTestPath($test->filePath);
        if (empty($test->stepComments)) {
            return sprintf('test "%s" (%s:%d): (no @Given/@When/@Then comments)', $test->name, $relativePath, $test->line);
        }

        $steps = array_map(
            static fn (StepDoc $step): string => sprintf('%s %s', $step->keyword, $step->text),
            $test->stepComments
        );

        return sprintf('test "%s" (%s:%d): %s', $test->name, $relativePath, $test->line, implode(' -> ', $steps));
    }

    private static function formatTestNamesList(array $tests): string
    {
        if (empty($tests)) {
            return '(no tests found)';
        }

        $names = array_map(
            static fn (TestBlock $test): string => sprintf('%s (%s:%d)', $test->name, self::relativeTestPath($test->filePath), $test->line),
            $tests
        );

        return implode(', ', $names);
    }

    private static function primaryTestReference(array $tests): ?string
    {
        if (empty($tests)) {
            return null;
        }

        $test = $tests[0];

        return sprintf('%s:%d', self::relativeTestPath($test->filePath), $test->line);
    }

    private static function formatStepLabel(FeatureDoc $feature, ScenarioDoc $scenario, StepDoc $step): string
    {
        $featureLabel = $feature->title ?: $feature->basename;

        return sprintf(
            '%s -> %s -> %s %s',
            $featureLabel,
            $scenario->title,
            $step->keyword,
            $step->text
        );
    }

    private static function normalizeStepSignature(string $keyword, string $text): string
    {
        $keyword = strtolower(trim($keyword));
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text, " .\t\n\r\0\v");
        $text = str_replace(['"', '\'', '<', '>'], '', $text);

        $prefixes = ['given ', 'when ', 'then ', 'and ', 'but '];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                $text = substr($text, strlen($prefix));
                break;
            }
        }

        return $keyword.' '.$text;
    }

    private static function matchesTestName(string $scenarioTitle, string $testName): bool
    {
        $scenarioNorm = self::normalizeName($scenarioTitle);
        $testNorm = self::normalizeName($testName);

        if ($scenarioNorm === $testNorm) {
            return true;
        }

        $scenarioPlaceholders = self::normalizePlaceholders($scenarioNorm);
        $testPlaceholders = self::normalizePlaceholders($testNorm);

        if ($scenarioPlaceholders === $testPlaceholders) {
            return true;
        }

        $scenarioPattern = self::textToPattern($scenarioNorm);
        if ($scenarioPattern !== null && preg_match($scenarioPattern, $testNorm)) {
            return true;
        }

        $testPattern = self::textToPattern($testNorm);
        if ($testPattern !== null && preg_match($testPattern, $scenarioNorm)) {
            return true;
        }

        return false;
    }

    private static function normalizeName(string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }

    private static function normalizePlaceholders(string $text): string
    {
        $text = preg_replace('/"[^"]+"/', ':placeholder', $text);
        $text = preg_replace('/<[^>]+>/', ':placeholder', $text);
        $text = str_replace(':dataset', ':placeholder', $text);

        return $text;
    }

    private static function textToPattern(string $text): ?string
    {
        $pattern = '';
        $offset = 0;
        $hasPlaceholder = false;

        while (preg_match('/("[^"]+"|<[^>]+>|:dataset)/', $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $hasPlaceholder = true;
            $pattern .= preg_quote(substr($text, $offset, $match[0][1] - $offset), '/');
            $pattern .= '(.+)';
            $offset = $match[0][1] + strlen($match[0][0]);
        }

        if (! $hasPlaceholder) {
            return null;
        }

        $pattern .= preg_quote(substr($text, $offset), '/');

        return '/^'.$pattern.'$/';
    }

    private static function relativeTestPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', self::testsBasePath());

        if (str_starts_with($normalized, $base)) {
            $relative = substr($normalized, strlen($base));
            $relative = ltrim($relative, '/');

            return 'tests/'.$relative;
        }

        return $normalized;
    }

    private static function offsetToLineNumber(string $content, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        $portion = substr($content, 0, $offset);

        return substr_count($portion, "\n") + 1;
    }

    private static function hasStepComment(array $tests, string $normalizedSignature): bool
    {
        foreach ($tests as $test) {
            foreach ($test->stepComments as $comment) {
                if (self::normalizeStepSignature($comment->keyword, $comment->text) === $normalizedSignature) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function formatSequenceCoverage(FeatureDoc $feature, ScenarioDoc $scenario, array $tests): string
    {
        if (empty($scenario->steps)) {
            $featurePath = self::relativeTestPath($feature->path);

            return sprintf(
                "📄 Feature: %s (%s)\n🎯 Scenario: %s (%s:%d)\n    (no steps defined)",
                $feature->title ?: $feature->basename,
                $featurePath,
                $scenario->title,
                $featurePath,
                $scenario->line
            );
        }

        $red = "\033[31m";
        $green = "\033[32m";
        $yellow = "\033[33m";
        $blue = "\033[34m";
        $purple = "\033[35m";
        $bgRed = "\033[41m";
        $bgGreen = "\033[42m";
        $reset = "\033[0m";

        $lines = [];
        $featureLabel = $feature->title ?: $feature->basename;
        $featurePath = self::relativeTestPath($feature->path);
        $lines[] = sprintf('📄  Feature: %s (%s)', $featureLabel, $featurePath);
        $lines[] = sprintf('🎯  Scenario: %s (%s:%d)', $scenario->title, $featurePath, $scenario->line);
        foreach ($scenario->steps as $index => $step) {
            $signature = self::normalizeStepSignature($step->keyword, $step->text);
            $matchInfo = self::findStepCommentLocation($tests, $signature);
            $found = $matchInfo !== null;
            $icon = $found ? "{$green}✔{$reset}" : "{$red}✘{$reset}";
            $lines[] = sprintf('    %s %s %s', $icon, $step->keyword, $step->text);
            $lines[] = sprintf('        — %s:%d', $featurePath, $step->line);

            if ($matchInfo !== null) {
                $testBlock = $matchInfo['test'];
                $comment = $matchInfo['step'];
                $lines[] = sprintf(
                    '        — %s:%d',
                    self::relativeTestPath($testBlock->filePath),
                    $comment->line,
                );
            }
        }

        return implode("\n", $lines);
    }

    private static function findStepCommentLocation(array $tests, string $signature): ?array
    {
        foreach ($tests as $test) {
            foreach ($test->stepComments as $step) {
                if (self::normalizeStepSignature($step->keyword, $step->text) === $signature) {
                    return ['test' => $test, 'step' => $step];
                }
            }
        }

        return null;
    }

    private static function resolveFeatureTestPaths(FeatureDoc $feature): ?array
    {
        if (! $feature->hasTestsSection) {
            return null;
        }

        $resolved = [];
        foreach ($feature->tests as $test) {
            $path = self::resolvePathInput($test, mustBeFile: true);
            if ($path !== null) {
                $resolved[] = $path;
            }
        }

        return array_values(array_unique($resolved));
    }

    private static function formatTestPathsList(array $paths): string
    {
        if (empty($paths)) {
            return '(no test files found)';
        }

        $relative = array_map(
            static fn (string $path): string => self::relativeTestPath($path),
            $paths
        );

        return implode(', ', $relative);
    }
}
