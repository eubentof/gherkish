<?php

declare(strict_types=1);

namespace Gherkish\Mapping;

use Gherkish\Examples\ExamplesException;
use Gherkish\Gherkin\FeatureParser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FeatureMap
{
    public readonly string $root;

    public function __construct(string $root)
    {
        $this->root = realpath($root) ?: rtrim($root, '/');
    }

    public static function forTest(string $testPath): self
    {
        $directory = dirname($testPath);
        while (true) {
            if (is_file($directory.'/composer.json')) {
                return new self($directory);
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return new self(getcwd() ?: dirname($testPath));
            }
            $directory = $parent;
        }
    }

    /** @return list<string> */
    public function files(string $extension): array
    {
        $paths = [];
        foreach (['app', 'tests'] as $directory) {
            if (! is_dir($this->root.'/'.$directory)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                    $paths[] = $file->getRealPath();
                }
            }
        }
        sort($paths);

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    public function annotatedFeatures(string $testPath): array
    {
        $paths = [];
        $declaring = false;
        foreach (token_get_all(file_get_contents($testPath)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                if (preg_match_all('/@feature\s+([^\r\n*]+)/i', $token[1], $matches)) {
                    foreach ($matches[1] as $path) {
                        $paths[] = $this->existing(trim($path), '.feature');
                    }
                }
            } elseif (is_array($token) && $token[0] === T_DECLARE) {
                $declaring = true;
            } elseif ($declaring) {
                if ($token === ';') {
                    $declaring = false;
                }
            } elseif (! is_array($token) || ! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true)) {
                break;
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    public function declaredTests(string $featurePath, bool $strict = true): array
    {
        $paths = [];
        foreach ((new FeatureParser)->parse($featurePath)->tests as $path) {
            try {
                $paths[] = $this->existing($path, '.php');
            } catch (ExamplesException $exception) {
                if ($strict) {
                    throw $exception;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    public function featuresForTest(string $testPath): array
    {
        $annotated = $this->annotatedFeatures($testPath);
        if ($annotated !== []) {
            return $annotated;
        }
        $sibling = preg_replace('/Test\.php$/', '.feature', $testPath);
        if ($sibling !== $testPath && is_file($sibling)) {
            return [$sibling];
        }
        $paths = [];
        foreach ($this->files('.feature') as $feature) {
            if (in_array(realpath($testPath), $this->declaredTests($feature, strict: false), true)) {
                $paths[] = $feature;
            }
        }
        if ($paths === []) {
            throw new ExamplesException(sprintf('Gherkish could not find the feature paired with "%s". Expected "%s", a top-of-file @feature annotation, or an @tests: declaration under app/ or tests/.', $testPath, $sibling));
        }

        return $paths;
    }

    /**
     * Validate the complete mapping before writing any files.
     *
     * @return list<string>
     */
    public function synchronize(bool $write = true): array
    {
        $mapping = [];
        $annotations = [];
        foreach ($this->files('.feature') as $feature) {
            $mapping[$feature] = $this->declaredTests($feature);
            if ((new FeatureParser)->parse($feature)->mappingLines === []) {
                $sibling = preg_replace('/\.feature$/', 'Test.php', $feature);
                if (is_file($sibling)) {
                    $mapping[$feature][] = realpath($sibling);
                }
            }
        }
        foreach ($this->files('.php') as $test) {
            $annotations[$test] = $this->annotatedFeatures($test);
            foreach ($annotations[$test] as $feature) {
                $mapping[$feature] ??= $this->declaredTests($feature);
                $mapping[$feature][] = $test;
            }
        }
        $testFeatures = [];
        $changes = [];
        foreach ($mapping as $feature => $tests) {
            $tests = array_values(array_unique($tests));
            sort($tests);
            if ($tests === []) {
                continue;
            }
            foreach ($tests as $test) {
                $testFeatures[$test][] = $feature;
            }
            $content = file_get_contents($feature);
            $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
            $section = '  @tests:'.$newline;
            foreach ($tests as $test) {
                $section .= '  - '.$this->relative($test).$newline;
            }
            $document = (new FeatureParser)->parse($feature);
            $lines = preg_split('/\r\n|\r|\n/', $content);
            // Insert before the first child's tags as well as its keyword.
            $child = $document->feature?->children[0] ?? null;
            $insertLine = $child === null ? count($lines) + 1 : $child->line;
            foreach ($child?->tags ?? [] as $tag) {
                $insertLine = min($insertLine, $tag['line']);
            }
            $updatedLines = [];
            foreach ($lines as $index => $line) {
                if ($index + 1 === $insertLine) {
                    $updatedLines[] = rtrim($section, "\r\n");
                    $updatedLines[] = '';
                }
                if (! in_array($index + 1, $document->mappingLines, true)) {
                    $updatedLines[] = $line;
                }
            }
            $updated = implode($newline, $updatedLines);
            if ($child === null) {
                $updated = rtrim($updated).$newline.$newline.$section;
            }
            // Keep an already synchronized section in its original position.
            if ($this->declaredTests($feature) !== $tests || $document->mappingLines === []) {
                $changes[$feature] = $updated;
            }
        }
        foreach ($testFeatures as $test => $features) {
            $existing = $annotations[$test] ?? $this->annotatedFeatures($test);
            $missing = array_diff($features, $existing);
            if ($missing === []) {
                continue;
            }
            sort($missing);
            $content = file_get_contents($test);
            if (! str_starts_with($content, '<?php')) {
                throw new ExamplesException(sprintf('Cannot annotate test without an opening PHP tag: "%s".', $test));
            }
            $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
            $header = '';
            foreach ($missing as $feature) {
                $header .= $newline.'// @feature '.$this->relative($feature);
            }
            $changes[$test] = '<?php'.$header.$newline.substr($content, 5);
        }
        if ($write) {
            foreach ($changes as $path => $content) {
                if (file_put_contents($path, $content) === false) {
                    throw new ExamplesException(sprintf('Could not synchronize "%s".', $path));
                }
            }
        }

        return array_map($this->relative(...), array_keys($changes));
    }

    public function relative(string $path): string
    {
        $prefix = rtrim($this->root, '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function existing(string $path, string $extension): string
    {
        $candidates = str_starts_with($path, '/') ? [$path] : [$this->root.'/'.$path, $this->root.'/tests/'.$path];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && str_ends_with($candidate, $extension)) {
                return realpath($candidate);
            }
        }

        throw new ExamplesException(sprintf('Gherkish mapping points to a missing or invalid file: "%s".', $path));
    }
}
