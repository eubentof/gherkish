<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class FeatureParitySelection
{
    public function __construct(
        public ?string $dir = null,
        public ?string $file = null,
        public ?string $rawDir = null,
        public ?string $rawFile = null,
    ) {}
}
