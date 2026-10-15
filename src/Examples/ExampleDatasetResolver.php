<?php

declare(strict_types=1);

namespace Gherkish\Examples;

use Gherkish\Gherkin\FeatureParser;
use Gherkish\Gherkin\Table;
use Gherkish\Mapping\FeatureMap;

final class ExampleDatasetResolver
{
    /**
     * @return list<array<string, string>>
     */
    public function resolve(string $testPath, int $callLine, ?string $label = null): array
    {
        $features = FeatureMap::forTest($testPath)->featuresForTest($testPath);
        $outlines = [];
        $paths = [];
        foreach ($features as $featurePath) {
            foreach ($this->outlines($featurePath) as $outline) {
                $outlines[] = $outline;
                $paths[spl_object_id($outline)] = $featurePath;
            }
        }
        if ($outlines === []) {
            throw new ExamplesException(sprintf('No Scenario Outline was found in "%s".', implode('", "', $features)));
        }

        $outline = $this->resolveOutline($outlines, $testPath, $callLine);
        $featurePath = $paths[spl_object_id($outline)];
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
                'Scenario Outline "%s" has multiple Examples blocks. Pass one or more of these labels to Gherkish::examples(): %s.',
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
    public function outlines(string $featurePath): array
    {
        $document = (new FeatureParser)->parse($featurePath);
        $outlines = [];
        foreach ($document->scenarios as $scenario) {
            if ($scenario->kind !== 'outline') {
                continue;
            }
            $blocks = [];
            foreach ($scenario->children as $block) {
                if ($block->table === null) {
                    throw new ExamplesException(sprintf('Examples block at %s:%d has no table.', $featurePath, $block->line));
                }
                $rows = $block->table->rows;
                $headers = $rows[0];
                if ($headers === [] || count($headers) !== count(array_unique($headers)) || in_array('', $headers, true)) {
                    throw new ExamplesException(sprintf('Examples header at %s:%d must contain unique, non-empty column names.', $featurePath, $block->table->line));
                }
                foreach (array_slice($rows, 1) as $index => $row) {
                    if (count($row) !== count($headers)) {
                        throw new ExamplesException(sprintf('Examples row at %s:%d has %d cells; expected %d.', $featurePath, $block->table->rowLines[$index + 1], count($row), count($headers)));
                    }
                }
                $blocks[] = new ExampleBlock($block->name === '' ? null : $block->name, Table::associative($rows), $block->line);
            }
            $outlines[] = new ScenarioOutline($scenario->name, $blocks, $scenario->line);
        }

        return $outlines;
    }

    public function testNameAtLine(string $testPath, int $callLine): ?string
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

    public function namesMatch(string $scenarioTitle, string $testName): bool
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
