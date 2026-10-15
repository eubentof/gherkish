<?php

declare(strict_types=1);

namespace Gherkish\Gherkin;

final class Document
{
    public ?Node $feature = null;

    public array $scenarios = [];

    public array $comments = [];

    public array $tests = [];

    public array $mappingLines = [];

    public array $errors = [];

    public function __construct(public string $path, public string $maskedSource) {}

    public function error(int $line, int $column, string $code, string $message): void
    {
        $this->errors[] = compact('line', 'column', 'code', 'message') + ['path' => $this->path];
    }

    /** @return list<Node> */
    public function backgrounds(?Node $scenario = null): array
    {
        $backgrounds = [];
        foreach ($this->feature?->children ?? [] as $child) {
            if ($child->kind === 'background') {
                $backgrounds[] = $child;
            } elseif ($child->kind === 'rule' && ($scenario === null || in_array($scenario, $child->children, true))) {
                foreach ($child->children as $ruleChild) {
                    if ($ruleChild->kind === 'background') {
                        $backgrounds[] = $ruleChild;
                    }
                }
            }
        }

        return $backgrounds;
    }
}
