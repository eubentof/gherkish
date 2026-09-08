<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class FeatureParityResult
{
    /**
     * @param  array<int, array{label:string,message:string}>  $errors
     * @param  array<int, array{label:string,message:string}>  $skipped
     * @param  string[]  $successes
     * @param  array<int, array{status:string,label:string,message:string|null,testPath:string,steps:array<int,array{status:string,label:string}>,examples:list<array{block:int,label:string|null,values:array<string,string>}>}>  $cases
     */
    public function __construct(
        public int $scenarios = 0,
        public array $errors = [],
        public array $skipped = [],
        public array $successes = [],
        public array $cases = [],
    ) {}

    public function addSuccess(string $label, string $testPath = '', array $steps = [], array $examples = []): void
    {
        $this->successes[] = $label;
        $this->cases[] = [
            'status' => 'passed',
            'label' => $label,
            'message' => null,
            'testPath' => $testPath,
            'steps' => $steps,
            'examples' => $examples,
        ];
        $this->scenarios++;
    }

    public function addError(string $label, string $message, string $testPath = '', array $steps = [], array $examples = []): void
    {
        $this->errors[] = ['label' => $label, 'message' => $message];
        $this->cases[] = [
            'status' => 'failed',
            'label' => $label,
            'message' => $message,
            'testPath' => $testPath,
            'steps' => $steps,
            'examples' => $examples,
        ];
        $this->scenarios++;
    }

    public function addSkipped(string $label, string $message, string $testPath = '', array $steps = [], array $examples = []): void
    {
        $this->skipped[] = ['label' => $label, 'message' => $message];
        $this->cases[] = [
            'status' => 'skipped',
            'label' => $label,
            'message' => $message,
            'testPath' => $testPath,
            'steps' => $steps,
            'examples' => $examples,
        ];
        $this->scenarios++;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
