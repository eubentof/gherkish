<?php

declare(strict_types=1);

namespace Gherkish;

use Gherkish\Arguments\PhpContext;
use Gherkish\Arguments\StepArgumentException;
use Gherkish\Arguments\StepArgumentResolver;
use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;

final class Gherkish
{
    /** @return list<array<string, string>>|list<list<string>> */
    public static function dataTable(?string $step = null, ?string $scenario = null, array $values = [], bool $raw = false): array
    {
        [$path, $line] = self::argumentCaller();
        $resolver = new StepArgumentResolver;

        return $resolver->table($resolver->resolve($path, $line, 'table', $step, $scenario, $values), $raw);
    }

    public static function docs(?string $title = null, ?string $step = null, ?string $scenario = null, array $values = []): string
    {
        [$path, $line] = self::argumentCaller();
        $resolver = new StepArgumentResolver;

        return $resolver->body($resolver->resolve($path, $line, 'docString', $step, $scenario, $values), $title);
    }

    private static function argumentCaller(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $path = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            if (is_string($path) && is_int($line) && ! str_starts_with($path, __DIR__.DIRECTORY_SEPARATOR) && PhpContext::at($path, $line) !== null) {
                return [$path, $line];
            }
        }

        throw new StepArgumentException('Gherkish could not determine the Pest callback requesting a step argument.');
    }

    /**
     * Return the current Scenario Outline's Examples rows as a Pest dataset.
     *
     * @return list<array<string, string>>
     */
    public static function examples(?string ...$labels): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (! is_string($file) || ! is_int($line) || str_starts_with($file, __DIR__)) {
                continue;
            }

            $resolver = new ExampleDatasetResolver;
            if ($labels === []) {
                return $resolver->resolve($file, $line);
            }

            $rows = [];
            foreach ($labels as $label) {
                array_push($rows, ...$resolver->resolve($file, $line, $label));
            }

            return $rows;
        }

        throw new ExamplesException('Gherkish could not determine the Pest test that requested the examples dataset.');
    }
}
