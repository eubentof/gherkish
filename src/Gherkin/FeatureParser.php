<?php

declare(strict_types=1);

namespace Gherkish\Gherkin;

use Cucumber\Gherkin\GherkinParser;

/** Permissive reader for legacy files; the pinned Cucumber grammar validates strict input. */
final class FeatureParser
{
    public function parse(string $path, bool $validate = false): Document
    {
        $source = file_get_contents($path);
        $document = new Document($path, $source === false ? '' : $source);
        $lines = preg_split('/\r\n|\r|\n/', $document->maskedSource);
        $masked = $lines;
        $owner = null;
        $outline = null;
        $rule = null;
        $step = null;
        $table = null;
        $fence = null;
        $docLines = [];
        $tags = [];
        $mapping = false;
        $hasChild = false;

        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $text = trim($line);
            $column = strlen($line) - strlen(ltrim($line)) + 1;
            if ($fence !== null) {
                if ($text === $fence->keyword) {
                    $fence->content = implode("\n", $docLines);
                    $fence = null;
                } else {
                    $indent = $fence->column - 1;
                    $dedented = preg_replace('/^[ \t]{0,'.$indent.'}/', '', $line);
                    $docLines[] = str_replace('\\'.implode('\\', str_split($fence->keyword)), $fence->keyword, $dedented);
                }

                continue;
            }
            if ($mapping && preg_match('/^-\s*(.+)$/', $text, $match)) {
                $document->tests[] = trim($match[1]);
                $document->mappingLines[] = $number;
                $masked[$index] = str_repeat(' ', strlen($line));

                continue;
            }
            if ($text === '' || str_starts_with($text, '#')) {
                if (str_starts_with($text, '#')) {
                    $document->comments[] = ['line' => $number, 'text' => $text];
                    if ($validate && preg_match('/^#\s*language:\s*(\S+)/', $text, $match) && $match[1] !== 'en') {
                        $document->error($number, $column, 'unsupported-language', 'Only English Gherkin is supported.');
                    }
                }

                continue;
            }
            $mapping = false;
            if (preg_match('/^@tests:\s*$/i', $text)) {
                if ($hasChild || $document->feature === null) {
                    $document->error($number, $column, 'misplaced-tests', '@tests: must be feature-level metadata before Background, Rule, or Scenario.');
                } else {
                    $mapping = true;
                    $document->mappingLines[] = $number;
                    $masked[$index] = str_repeat(' ', strlen($line));
                }

                continue;
            }
            if (preg_match('/^("""|```)(.*)$/', $text, $match)) {
                $fence = new Node('docString', $match[1], '', $number, $column);
                $fence->contentType = trim($match[2]) ?: null;
                $docLines = [];
                if ($step === null) {
                    $document->error($number, $column, 'orphan-argument', 'Doc String requires a preceding step.');
                } elseif ($step->docString !== null) {
                    $document->error($number, $column, 'duplicate-argument', 'A step cannot have two Doc Strings.');
                } else {
                    $step->docString = $fence;
                }
                $table = null;

                continue;
            }
            if (str_starts_with($text, '|')) {
                if ($table === null) {
                    $table = new Node('table', '|', '', $number, $column);
                    if ($owner?->kind === 'examples') {
                        $owner->table = $table;
                    } elseif ($step !== null && $step->table === null) {
                        $step->table = $table;
                    } else {
                        $document->error($number, $column, 'orphan-argument', 'Data Table requires a step or Examples block.');
                    }
                }
                $row = Table::row($line);
                if ($table->rows !== [] && count($row) !== count($table->rows[0])) {
                    $document->error($number, $column, 'table-width', 'Table rows must have consistent cell counts.');
                }
                $table->rows[] = $row;
                $table->rowLines[] = $number;

                continue;
            }
            $table = null;
            if (str_starts_with($text, '@')) {
                foreach (preg_split('/\s+/', $text) as $tag) {
                    $tags[] = ['name' => $tag, 'line' => $number, 'column' => strpos($line, $tag) + 1];
                }
                $step = null;

                continue;
            }
            if (preg_match('/^(Feature|Rule|Background|Scenario Outline|Scenario Template|Scenario|Example|Examples|Scenarios):\s*(.*)$/i', $text, $match)) {
                $keyword = $match[1];
                $kind = match (strtolower($keyword)) {
                    'feature' => 'feature',
                    'rule' => 'rule',
                    'background' => 'background',
                    'examples', 'scenarios' => 'examples',
                    'scenario outline', 'scenario template' => 'outline',
                    // Retain the old singular Examples spelling only in compatibility mode.
                    'example' => ! $validate && $outline !== null && $this->legacyExamples($lines, $index) ? 'examples' : 'scenario',
                    default => 'scenario',
                };
                $node = new Node($kind, $keyword, trim($match[2]), $number, $column);
                $node->tags = $tags;
                $tags = [];
                $step = null;
                if ($kind === 'feature') {
                    if ($document->feature !== null) {
                        $document->error($number, $column, 'duplicate-feature', 'A feature file may contain only one Feature.');
                    }
                    $document->feature ??= $node;
                    $rule = null;
                } elseif ($kind === 'rule') {
                    if ($document->feature !== null) {
                        $document->feature->children[] = $node;
                    }
                    $rule = $node;
                    $hasChild = true;
                } elseif ($kind === 'examples') {
                    if ($outline !== null) {
                        $outline->children[] = $node;
                    }
                } else {
                    $container = $rule ?? $document->feature;
                    if ($container !== null) {
                        $container->children[] = $node;
                    }
                    if (in_array($kind, ['scenario', 'outline'], true)) {
                        $document->scenarios[] = $node;
                    }
                    $hasChild = true;
                }
                if ($kind !== 'examples') {
                    $outline = $kind === 'outline' ? $node : null;
                }
                $owner = $node;

                continue;
            }
            if (preg_match('/^(Given|When|Then|And|But|\*)\s+(.*)$/i', $text, $match)) {
                $step = new Node('step', ucfirst(strtolower($match[1])), trim($match[2]), $number, $column);
                if ($owner !== null) {
                    $owner->steps[] = $step;
                }

                continue;
            }
            if ($owner !== null && $owner->steps === []) {
                $owner->description .= ($owner->description === '' ? '' : "\n").$line;
            }
            $step = null;
        }
        if ($fence !== null) {
            $fence->content = implode("\n", $docLines);
            $document->error($fence->line, $fence->column, 'unclosed-doc-string', 'Doc String opened here has no closing delimiter.');
        }
        foreach ($document->mappingLines as $mappingIndex => $mappingLine) {
            if (preg_match('/^\h*@tests:/i', $lines[$mappingLine - 1])) {
                $next = $document->mappingLines[$mappingIndex + 1] ?? null;
                if ($next === null || ! preg_match('/^\h*-\s*\S+/', $lines[$next - 1])) {
                    $document->error($mappingLine, 1, 'invalid-tests', '@tests: requires one or more non-empty test paths prefixed with -.');
                }
            }
        }
        $document->maskedSource = implode("\n", $masked);
        if ($validate) {
            $this->validate($document);
        }

        return $document;
    }

    private function legacyExamples(array $lines, int $index): bool
    {
        foreach (array_slice($lines, $index + 1) as $line) {
            $text = trim($line);
            if (str_starts_with($text, '|')) {
                return true;
            }
            if (preg_match('/^(?:Feature|Rule|Background|Scenario|Example|Given|When|Then|And|But|\*)\b/i', $text)) {
                return false;
            }
        }

        return false;
    }

    private function validate(Document $document): void
    {
        if ($document->feature === null) {
            $document->error(1, 1, 'missing-feature', 'A feature file must contain one Feature.');
        }
        foreach ($document->scenarios as $scenario) {
            if ($scenario->kind === 'outline' && $scenario->children === []) {
                $document->error($scenario->line, $scenario->column, 'missing-examples', 'Scenario Outline requires an Examples block.');
            }
        }
        $parser = new GherkinParser(includeSource: false, includePickles: false);
        foreach ($parser->parseString($document->path, $document->maskedSource) as $envelope) {
            if ($envelope->parseError !== null) {
                $error = $envelope->parseError;
                $document->error($error->source->location->line, $error->source->location->column ?? 1, 'gherkin-syntax', $error->message);
            }
        }
    }
}
