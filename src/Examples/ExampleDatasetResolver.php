<?php

declare(strict_types=1);

namespace Gherkish\Examples;

final class ExampleDatasetResolver
{
    /**
     * @return list<array<string, string>>
     */
    public function resolve(string $testPath, int $callLine, ?string $label = null): array
    {
        $featurePath = preg_replace('/Test\.php$/', '.feature', $testPath);

        if ($featurePath === null || $featurePath === $testPath || ! is_file($featurePath)) {
            throw new ExamplesException(sprintf(
                'Gherkish could not find the feature paired with "%s". Expected "%s".',
                $testPath,
                $featurePath ?? $testPath,
            ));
        }

        $outlines = $this->parseOutlines($featurePath);
        if ($outlines === []) {
            throw new ExamplesException(sprintf('No Scenario Outline was found in "%s".', $featurePath));
        }

        $outline = $this->resolveOutline($outlines, $testPath, $callLine);
        $block = $this->resolveBlock($outline, $label, $featurePath);

        return $block->rows;
    }

    /**
     * @param  list<ScenarioOutline>  $outlines
     */
    private function resolveOutline(array $outlines, string $testPath, int $callLine): ScenarioOutline
    {
        if (count($outlines) === 1) {
            return $outlines[0];
        }

        $testName = $this->testNameAtLine($testPath, $callLine);
        if ($testName === null) {
            throw new ExamplesException(sprintf(
                'Gherkish could not determine the Pest test at %s:%d. The paired feature contains multiple Scenario Outlines.',
                $testPath,
                $callLine,
            ));
        }

        $matches = array_values(array_filter(
            $outlines,
            fn (ScenarioOutline $outline): bool => $this->namesMatch($outline->title, $testName),
        ));

        if (count($matches) !== 1) {
            throw new ExamplesException(sprintf(
                'The Pest test "%s" at %s:%d must match exactly one Scenario Outline. Available outlines: %s.',
                $testName,
                $testPath,
                $callLine,
                implode(', ', array_map(fn (ScenarioOutline $outline): string => '"'.$outline->title.'"', $outlines)),
            ));
        }

        return $matches[0];
    }

    private function resolveBlock(ScenarioOutline $outline, ?string $label, string $featurePath): ExampleBlock
    {
        if ($outline->examples === []) {
            throw new ExamplesException(sprintf(
                'Scenario Outline "%s" in "%s" has no Examples block.',
                $outline->title,
                $featurePath,
            ));
        }

        if ($label === null) {
            if (count($outline->examples) === 1) {
                return $outline->examples[0];
            }

            throw new ExamplesException(sprintf(
                'Scenario Outline "%s" has multiple Examples blocks. Pass one of these labels to Gherkish::examples(): %s.',
                $outline->title,
                $this->formatLabels($outline),
            ));
        }

        $matches = array_values(array_filter(
            $outline->examples,
            static fn (ExampleBlock $block): bool => $block->label === trim($label),
        ));

        if (count($matches) !== 1) {
            throw new ExamplesException(sprintf(
                'Examples label "%s" was not found exactly once in Scenario Outline "%s". Available labels: %s.',
                $label,
                $outline->title,
                $this->formatLabels($outline),
            ));
        }

        return $matches[0];
    }

    private function formatLabels(ScenarioOutline $outline): string
    {
        return implode(', ', array_map(
            static fn (ExampleBlock $block): string => $block->label === null ? '(unlabeled)' : '"'.$block->label.'"',
            $outline->examples,
        ));
    }

    /**
     * @return list<ScenarioOutline>
     */
    private function parseOutlines(string $featurePath): array
    {
        $lines = file($featurePath, FILE_IGNORE_NEW_LINES) ?: [];
        $outlines = [];
        $current = null;
        $currentBlock = null;

        $flushBlock = function () use (&$current, &$currentBlock, $featurePath): void {
            if ($current === null || $currentBlock === null) {
                return;
            }

            if ($currentBlock['headers'] === null) {
                throw new ExamplesException(sprintf(
                    'Examples block at %s:%d has no table.',
                    $featurePath,
                    $currentBlock['line'],
                ));
            }

            $current['examples'][] = new ExampleBlock(
                $currentBlock['label'],
                $currentBlock['rows'],
                $currentBlock['line'],
            );
            $currentBlock = null;
        };

        $flushOutline = function () use (&$current, &$outlines, $flushBlock): void {
            $flushBlock();
            if ($current === null) {
                return;
            }

            $outlines[] = new ScenarioOutline($current['title'], $current['examples'], $current['line']);
            $current = null;
        };

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if (preg_match('/^Scenario Outline:\s*(.+)$/i', $trimmed, $match)) {
                $flushOutline();
                $current = ['title' => trim($match[1]), 'examples' => [], 'line' => $lineNumber];

                continue;
            }

            if (preg_match('/^Scenario:\s*/i', $trimmed)) {
                $flushOutline();

                continue;
            }

            if ($current !== null && preg_match('/^Examples?:\s*(.*)$/i', $trimmed, $match)) {
                $flushBlock();
                $blockLabel = trim($match[1]);
                $currentBlock = [
                    'label' => $blockLabel === '' ? null : $blockLabel,
                    'headers' => null,
                    'rows' => [],
                    'line' => $lineNumber,
                ];

                continue;
            }

            if ($currentBlock === null || ! str_starts_with($trimmed, '|')) {
                continue;
            }

            $cells = $this->parseTableRow($trimmed);
            if ($currentBlock['headers'] === null) {
                if ($cells === [] || count($cells) !== count(array_unique($cells)) || in_array('', $cells, true)) {
                    throw new ExamplesException(sprintf(
                        'Examples header at %s:%d must contain unique, non-empty column names.',
                        $featurePath,
                        $lineNumber,
                    ));
                }

                $currentBlock['headers'] = $cells;

                continue;
            }

            if (count($cells) !== count($currentBlock['headers'])) {
                throw new ExamplesException(sprintf(
                    'Examples row at %s:%d has %d cells; expected %d.',
                    $featurePath,
                    $lineNumber,
                    count($cells),
                    count($currentBlock['headers']),
                ));
            }

            $currentBlock['rows'][] = array_combine($currentBlock['headers'], $cells);
        }

        $flushOutline();

        return $outlines;
    }

    /**
     * @return list<string>
     */
    private function parseTableRow(string $line): array
    {
        $cells = [];
        $cell = '';
        $escaped = false;

        foreach (str_split(trim($line)) as $index => $character) {
            if ($index === 0 && $character === '|') {
                continue;
            }

            if ($escaped) {
                $cell .= in_array($character, ['|', '\\'], true) ? $character : '\\'.$character;
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            if ($character === '|') {
                $cells[] = trim($cell);
                $cell = '';

                continue;
            }

            $cell .= $character;
        }

        if ($escaped) {
            $cell .= '\\';
        }

        if ($cell !== '') {
            $cells[] = trim($cell);
        }

        return $cells;
    }

    private function testNameAtLine(string $testPath, int $callLine): ?string
    {
        $content = file_get_contents($testPath);
        if ($content === false) {
            return null;
        }

        $pattern = '~(?<![A-Za-z0-9_])(?:test|it)\s*\(\s*(["\'])(?<name>.+?)\1\s*,~is';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $start = $match[0][1];
            $end = $this->statementEndOffset($content, $start);
            $startLine = substr_count(substr($content, 0, $start), "\n") + 1;
            $endLine = substr_count(substr($content, 0, $end), "\n") + 1;

            if ($callLine >= $startLine && $callLine <= $endLine) {
                return stripcslashes($match['name'][0]);
            }
        }

        return null;
    }

    private function statementEndOffset(string $content, int $start): int
    {
        $depth = 0;
        $string = null;
        $escaped = false;
        $comment = null;
        $length = strlen($content);

        for ($index = $start; $index < $length; $index++) {
            $character = $content[$index];
            $next = $content[$index + 1] ?? null;

            if ($comment === 'line') {
                if ($character === "\n") {
                    $comment = null;
                }

                continue;
            }

            if ($comment === 'block') {
                if ($character === '*' && $next === '/') {
                    $comment = null;
                    $index++;
                }

                continue;
            }

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

            if ($character === '"' || $character === "'") {
                $string = $character;
            } elseif ($character === '/' && $next === '/') {
                $comment = 'line';
                $index++;
            } elseif ($character === '/' && $next === '*') {
                $comment = 'block';
                $index++;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($character === ';' && $depth === 0) {
                return $index;
            }
        }

        return $length - 1;
    }

    private function namesMatch(string $scenarioTitle, string $testName): bool
    {
        $scenario = strtolower(trim(preg_replace('/\s+/', ' ', $scenarioTitle) ?? $scenarioTitle));
        $test = strtolower(trim(preg_replace('/\s+/', ' ', $testName) ?? $testName));

        if ($scenario === $test) {
            return true;
        }

        $quoted = preg_quote($scenario, '/');
        $pattern = preg_replace(['/\\<[^>]+\\>/', '/"[^"]+"/', '/\\:dataset/'], '(.+)', $quoted);

        return $pattern !== null && $pattern !== $quoted && preg_match('/^'.$pattern.'$/', $test) === 1;
    }
}
