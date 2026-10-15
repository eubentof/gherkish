<?php

declare(strict_types=1);

namespace Gherkish\Gherkin;

use InvalidArgumentException;

final class Table
{
    /** @return list<string> */
    public static function row(string $line): array
    {
        $line = trim($line);
        $cells = [];
        $cell = '';
        $length = strlen($line);
        for ($index = 1; $index < $length; $index++) {
            $character = $line[$index];
            if ($character === '\\' && $index + 1 < $length) {
                $next = $line[++$index];
                $cell .= match ($next) {
                    'n' => "\n",
                    '|', '\\' => $next,
                    default => '\\'.$next,
                };
            } elseif ($character === '|') {
                // Trim syntax padding before decoding so escaped newlines survive.
                $cells[] = self::trimPadding($cell);
                $cell = '';
            } else {
                $cell .= $character;
            }
        }
        if ($cell !== '') {
            $cells[] = self::trimPadding($cell);
        }

        return $cells;
    }

    private static function trimPadding(string $cell): string
    {
        return trim($cell, " \t");
    }

    /** @param list<list<string>> $rows
     * @return list<array<string, string>>
     */
    public static function associative(array $rows): array
    {
        $headers = array_shift($rows) ?? [];
        if ($headers === [] || in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('Table headers must contain unique, non-empty column names. Use raw: true for a headerless step table.');
        }
        foreach ($rows as $row) {
            if (count($row) !== count($headers)) {
                throw new InvalidArgumentException('Table rows must have the same number of cells as the header.');
            }
        }

        return array_map(static fn (array $row): array => array_combine($headers, $row), $rows);
    }
}
