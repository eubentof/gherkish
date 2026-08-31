<?php

declare(strict_types=1);

namespace Gherkish\Examples;

final class ExampleBlock
{
    /**
     * @param  list<array<string, string>>  $rows
     */
    public function __construct(
        public readonly ?string $label,
        public readonly array $rows,
        public readonly int $line,
    ) {}
}
