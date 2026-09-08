<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class SetupBlock
{
    /**
     * @param  StepDoc[]  $stepComments
     * @param  StepDoc[]  $unimplementedStepComments
     * @param  int[]  $scope
     */
    public function __construct(
        public string $body,
        public array $stepComments,
        public string $filePath,
        public int $line,
        public array $unimplementedStepComments = [],
        public array $scope = [],
    ) {}
}
