<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class PestFile
{
    /**
     * @param  TestBlock[]  $tests
     * @param  SetupBlock[]  $setups
     */
    public function __construct(
        public string $path,
        public array $tests,
        public array $setups = [],
    ) {}
}
