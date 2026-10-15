<?php

declare(strict_types=1);

namespace Gherkish\Gherkin;

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;
use Gherkish\FeatureParity\FeatureDoc;
use Gherkish\FeatureParity\ScenarioDoc;
use Gherkish\FeatureParity\StepDoc;

final class FeatureAdapter
{
    public static function read(string $path): FeatureDoc
    {
        $document = (new FeatureParser)->parse($path);
        $outlines = [];
        try {
            foreach ((new ExampleDatasetResolver)->outlines($path) as $outline) {
                $outlines[$outline->line] = $outline;
            }
        } catch (ExamplesException) {
            // Preserve compatibility: extraction and --outlined own dataset errors.
        }
        $scenarios = [];
        foreach ($document->scenarios as $node) {
            $examples = [];
            foreach ($outlines[$node->line]->examples ?? [] as $index => $block) {
                foreach ($block->rows as $row) {
                    $examples[] = ['block' => $index, 'label' => $block->label, 'values' => $row];
                }
            }
            $rule = null;
            $ruleBackground = null;
            foreach ($document->feature?->children ?? [] as $child) {
                if ($child->kind === 'rule' && in_array($node, $child->children, true)) {
                    $rule = $child;
                    foreach ($child->children as $ruleChild) {
                        if ($ruleChild->kind === 'background') {
                            $ruleBackground = $ruleChild;
                        }
                    }
                }
            }
            $scenarios[] = new ScenarioDoc(
                $node->name, self::steps($node), $node->line, $examples, $node->kind === 'outline',
                $rule?->name, $rule?->line, self::steps($ruleBackground), $ruleBackground?->line,
            );
        }
        $background = null;
        $rules = [];
        foreach ($document->feature?->children ?? [] as $child) {
            if ($child->kind === 'background') {
                $background = $child;
            } elseif ($child->kind === 'rule') {
                $rules[] = $child;
            }
        }

        return new FeatureDoc(
            $path, pathinfo($path, PATHINFO_FILENAME), dirname($path), $scenarios,
            $document->feature?->name, $document->tests, $document->mappingLines !== [],
            self::steps($background), $background?->line, $rules,
        );
    }

    /** @return list<StepDoc> */
    public static function steps(?Node $node): array
    {
        return array_map(static fn (Node $step): StepDoc => new StepDoc($step->keyword, $step->name, $step->line), $node?->steps ?? []);
    }
}
