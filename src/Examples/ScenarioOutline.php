<?php

declare(strict_types=1);

namespace Gherkish\Examples;

final class ScenarioOutline
{
    /**
     * @param  list<ExampleBlock>  $examples
     */
    public function __construct(
        public readonly string $title,
        public readonly array $examples,
        public readonly int $line,
    ) {}
}
