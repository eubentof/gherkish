<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class ScenarioDoc
{
    /**
     * @param  StepDoc[]  $steps
     */
    public function __construct(
        public string $title,
        public array $steps,
        public int $line,
    ) {}
}
