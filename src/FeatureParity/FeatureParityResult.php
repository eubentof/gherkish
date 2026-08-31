<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class FeatureParityResult
{
    /**
     * @param  array<int, array{label:string,message:string}>  $errors
     * @param  array<int, array{label:string,message:string}>  $skipped
     * @param  string[]  $successes
     */
    public function __construct(
        public int $scenarios = 0,
        public array $errors = [],
        public array $skipped = [],
        public array $successes = [],
    ) {}

    public function addSuccess(string $label): void
    {
        $this->successes[] = $label;
        $this->scenarios++;
    }

    public function addError(string $label, string $message): void
    {
        $this->errors[] = ['label' => $label, 'message' => $message];
        $this->scenarios++;
    }

    public function addSkipped(string $label, string $message): void
    {
        $this->skipped[] = ['label' => $label, 'message' => $message];
        $this->scenarios++;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
