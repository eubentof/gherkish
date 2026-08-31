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
    public static function examples(?string $label = null): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (! is_string($file) || ! is_int($line) || str_starts_with($file, __DIR__)) {
                continue;
            }

            return (new ExampleDatasetResolver)->resolve($file, $line, $label);
        }

        throw new ExamplesException('Gherkish could not determine the Pest test that requested the examples dataset.');
    }
}
