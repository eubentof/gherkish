<?php

declare(strict_types=1);

namespace Gherkish;

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;

final class Gherkish
{
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
