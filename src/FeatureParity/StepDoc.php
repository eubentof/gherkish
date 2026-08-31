<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class StepDoc
{
    public function __construct(
        public string $keyword,
        public string $text,
        public int $line,
    ) {}
}
