<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class FeatureParityResult
{
    /**
     * @param  array<int, array{label:string,message:string}>  $errors
     * @param  array<int, array{label:string,message:string}>  $skipped
     * @param  string[]  $successes
     * @param  array<int, array{status:string,label:string,message:string|null,testPath:string,steps:array<int,array{status:string,label:string}>,examples:list<array{block:int,label:string|null,values:array<string,string>}>,examplesStatus:string|null}>  $cases
     * @param  array<int, array{label:string,message:string}>  $unmappedTests
     */
    public function __construct(
        public int $scenarios = 0,
        public array $errors = [],
        public array $skipped = [],
        public array $successes = [],
        public array $cases = [],
        public array $unmappedTests = [],
    ) {}

    public function addSuccess(
        string $label,
        string $testPath = '',
        array $steps = [],
        array $examples = [],
        string $examplesStatus = 'passed',
    ): void {
        $this->successes[] = $label;
        $this->cases[] = [
            'status' => 'passed',
            'label' => $label,
            'message' => null,
            'testPath' => $testPath,
            'steps' => $steps,
            'examples' => $examples,
            'examplesStatus' => $examples === [] ? null : $examplesStatus,
        ];
        $this->scenarios++;
    }

    public function addError(
        string $label,
        string $message,
        string $testPath = '',
        array $steps = [],
        array $examples = [],
        string $examplesStatus = 'skipped',
    ): void {
        $this->errors[] = ['label' => $label, 'message' => $message];
        $this->cases[] = [
            'status' => 'failed',
            'label' => $label,
            'message' => $message,
            'testPath' => $testPath,
            'steps' => $steps,
            'examples' => $examples,
            'examplesStatus' => $examples === [] ? null : $examplesStatus,
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
            'examplesStatus' => $examples === [] ? null : 'skipped',
        ];
        $this->scenarios++;
    }

    public function addUnmappedTest(string $label, string $message, string $testPath): void
    {
        $error = ['label' => $label, 'message' => $message];
        $this->errors[] = $error;
        $this->unmappedTests[] = $error;
        $this->cases[] = [
            'status' => 'failed',
            'label' => $label,
            'message' => $message,
            'testPath' => $testPath,
            'steps' => [],
            'examples' => [],
            'examplesStatus' => null,
        ];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
