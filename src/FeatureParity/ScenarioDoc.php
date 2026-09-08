<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class ScenarioDoc
{
    /**
     * @param  StepDoc[]  $steps
     * @param  list<array{block:int,label:string|null,values:array<string,string>}>  $examples
     */
    public function __construct(
        public string $title,
        public array $steps,
        public int $line,
        public array $examples = [],
        public bool $isOutline = false,
    ) {}
}
