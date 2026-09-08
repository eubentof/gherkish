<?php

declare(strict_types=1);

namespace Gherkish\FeatureParity;

final class GitStagedFileResolver implements StagedFileResolver
{
    public function resolve(string $projectPath): array
    {
        $projectPath = realpath($projectPath) ?: $projectPath;
        [$exitCode, $output, $error] = $this->run([
            'git',
            '-C',
            $projectPath,
            'diff',
            '--cached',
            '--name-only',
            '--diff-filter=ACMR',
            '--relative',
            '-z',
            '--',
            '.',
        ]);

        if ($exitCode !== 0) {
            $detail = trim($error) ?: 'Git exited without an error message.';

            throw new FeatureParityConfigurationException(
                'Could not read staged files from the Git index: '.$detail
            );
        }

        $paths = [];
        foreach (array_filter(explode("\0", $output), static fn (string $path): bool => $path !== '') as $path) {
            $absolutePath = $projectPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($absolutePath)) {
                $paths[] = realpath($absolutePath) ?: $absolutePath;
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param  string[]  $command
     * @return array{int, string, string}
     */
    private function run(array $command): array
    {
        if (! function_exists('proc_open')) {
            throw new FeatureParityConfigurationException(
                'Could not read staged files because proc_open() is unavailable.'
            );
        }

        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            throw new FeatureParityConfigurationException('Could not start Git to read staged files.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $output === false ? '' : $output, $error === false ? '' : $error];
    }
}
