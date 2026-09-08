<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

interface StagedFileResolver
{
    /**
     * @return string[]
     */
    public function resolve(string $projectPath): array;
}
