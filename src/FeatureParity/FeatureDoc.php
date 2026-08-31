<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class FeatureDoc
{
    /**
     * @param  ScenarioDoc[]  $scenarios
     */
    public function __construct(
        public string $path,
        public string $basename,
        public string $dir,
        public array $scenarios,
        public ?string $title,
        public array $tests,
        public bool $hasTestsSection,
    ) {}
}
