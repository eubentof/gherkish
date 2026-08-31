<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class TestBlock
{
    /**
     * @param  StepDoc[]  $stepComments
     */
    public function __construct(
        public string $name,
        public string $body,
        public array $stepComments,
        public string $filePath,
        public int $line,
    ) {}
}
