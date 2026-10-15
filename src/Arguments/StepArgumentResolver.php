<?php

declare(strict_types=1);

namespace Gherkish\Arguments;

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;
use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\Gherkin\FeatureParser;
use Gherkish\Gherkin\Table;
use Gherkish\Mapping\FeatureMap;
use InvalidArgumentException;

final class StepArgumentResolver
{
    private ?string $location = null;

    public function resolve(string $path, int $line, string $type, ?string $step, ?string $scenario, array $values = []): mixed
    {
        $context = PhpContext::at($path, $line);
        if ($context === null) {
            throw new StepArgumentException(sprintf('No Pest test or beforeEach callback contains %s:%d. Call the helper from a test context.', $path, $line));
        }
        try {
            $features = FeatureMap::forTest($path)->featuresForTest($path);
        } catch (ExamplesException $exception) {
            throw new StepArgumentException($exception->getMessage(), previous: $exception);
        }
        $names = $scenario !== null ? [$scenario] : ($context['kind'] === 'test' ? [$context['name']] : $this->setupScenarios($path, $context['line']));
        $matcher = new ExampleDatasetResolver;
        $annotation = $context['annotation'];
        $text = $step ?? ($annotation[1] ?? null);
        if ($text === null) {
            throw new StepArgumentException(sprintf('No step annotation at %s:%d. Add an annotation or pass step: and scenario: selectors.', $path, $line));
        }
        $matches = [];
        $setupMatches = [];
        $candidates = [];
        foreach ($features as $feature) {
            $document = (new FeatureParser)->parse($feature);
            if ($document->errors !== []) {
                $error = $document->errors[0];
                throw new StepArgumentException(sprintf('%s:%d [%s] %s (requested at %s:%d)', $feature, $error['line'], $error['code'], $error['message'], $path, $line));
            }
            foreach ($document->scenarios as $node) {
                $candidates[] = sprintf('scenario: "%s" (%s:%d)', $node->name, $feature, $node->line);
                if (! array_filter($names, static fn (string $name): bool => $matcher->namesMatch($node->name, $name))) {
                    continue;
                }
                $backgrounds = $document->backgrounds($node);
                $scenarioKey = $feature.':'.$node->line;
                $setupMatches[$scenarioKey] = [];
                $steps = $context['kind'] === 'setup' ? [] : $node->steps;
                foreach ($backgrounds as $background) {
                    array_push($steps, ...$background->steps);
                }
                foreach ($steps as $candidate) {
                    if ($this->normalize($candidate->name) !== $this->normalize($text)
                        || ($step === null && $annotation !== null && $candidate->keyword !== $annotation[0])) {
                        continue;
                    }
                    // Shared Background nodes are a single source even across scenarios.
                    $key = $feature.':'.$candidate->line;
                    $setupMatches[$scenarioKey][] = $key;
                    $argument = $type === 'table' ? $candidate->table : $candidate->docString;
                    $matches[$key] = [$candidate, $argument, $node->kind === 'outline'];
                }
            }
        }
        if (count($matches) !== 1) {
            throw new StepArgumentException(sprintf('Step "%s" at %s:%d must resolve exactly once; found %d. Use step: and scenario: selectors. Candidates: %s. Matched steps: %s.', $text, $path, $line, count($matches), implode(', ', array_unique($candidates)), implode(', ', array_keys($matches))));
        }
        $key = array_key_first($matches);
        if ($context['kind'] === 'setup' && $scenario === null) {
            foreach ($setupMatches as $keys) {
                if (! in_array($key, $keys, true)) {
                    throw new StepArgumentException(sprintf('Background step "%s" (%s) is not shared by every applicable scenario at %s:%d. Pass scenario: explicitly.', $text, $key, $path, $line));
                }
            }
        }
        $this->location = sprintf('%s; requested at %s:%d', $key, $path, $line);
        [$matched, $argument, $outline] = reset($matches);
        if ($argument === null) {
            throw new StepArgumentException(sprintf('Step "%s" (%s) has no %s argument; requested at %s:%d.', $matched->name, array_key_first($matches), $type, $path, $line));
        }
        $replace = function (string $value) use ($outline, $values, $path, $line): string {
            if (! $outline) {
                return $value;
            }

            return preg_replace_callback('/<([^>]+)>/', static function (array $match) use ($values, $path, $line): string {
                if (! array_key_exists($match[1], $values) || ! is_string($values[$match[1]])) {
                    throw new StepArgumentException(sprintf('Missing string value for outline placeholder <%s> at %s:%d. Pass values: [...].', $match[1], $path, $line));
                }

                return $values[$match[1]];
            }, $value);
        };

        return $type === 'table'
            ? array_map(static fn (array $row): array => array_map($replace, $row), $argument->rows)
            : $replace($argument->content);
    }

    public function table(array $rows, bool $raw): array
    {
        if ($raw) {
            return $rows;
        }
        try {
            return Table::associative($rows);
        } catch (InvalidArgumentException $exception) {
            throw new StepArgumentException($exception->getMessage().' '.$this->location, previous: $exception);
        }
    }

    public function body(string $content, ?string $title): string
    {
        if ($title === null) {
            return $content;
        }
        $lines = explode("\n", $content);
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        if (trim($lines[0] ?? '') !== trim($title) || ! preg_match('/^\h*(?:={3,}|-{3,})\h*$/', $lines[1] ?? '')) {
            throw new StepArgumentException(sprintf('Doc String must begin with Setext heading "%s" followed by an === or --- underline. %s', $title, $this->location));
        }
        $lines = array_slice($lines, 2);
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        return implode("\n", $lines);
    }

    private function setupScenarios(string $path, int $setupLine): array
    {
        $names = [];
        foreach (FeatureParityChecker::parsePestFile($path)->tests as $test) {
            foreach ($test->setupBlocks as $setup) {
                if ($setup->line === $setupLine) {
                    $names[] = $test->name;
                }
            }
        }

        return $names;
    }

    private function normalize(string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }
}
