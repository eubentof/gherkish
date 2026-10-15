<?php

declare(strict_types=1);

namespace Gherkish\Arguments;

/** Locates real Pest calls and step annotations without matching PHP string contents. */
final class PhpContext
{
    public static function at(string $path, int $line): ?array
    {
        $source = file_get_contents($path);
        if ($source === false) {
            return null;
        }
        $tokens = [];
        $offset = 0;
        $number = 1;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokens[] = ['id' => is_array($token) ? $token[0] : null, 'text' => $text, 'line' => $number, 'offset' => $offset];
            $number += substr_count($text, "\n");
            $offset += strlen($text);
        }
        $pairs = [];
        $stack = [];
        foreach ($tokens as $index => $token) {
            if (($token['id'] === null && in_array($token['text'], ['(', '[', '{'], true))
                || in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $stack[] = $index;
            } elseif ($token['id'] === null && in_array($token['text'], [')', ']', '}'], true)) {
                $opening = array_pop($stack);
                if ($opening !== null) {
                    $pairs[$opening] = $index;
                }
            }
        }
        $contexts = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STRING || ! in_array(strtolower($token['text']), ['it', 'test', 'beforeeach'], true)) {
                continue;
            }
            $previous = self::significant($tokens, $index, -1);
            if (in_array($tokens[$previous]['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $opening = self::significant($tokens, $index, 1);
            $closing = $pairs[$opening] ?? null;
            if (($tokens[$opening]['text'] ?? null) !== '(' || $closing === null) {
                continue;
            }
            $kind = strtolower($token['text']) === 'beforeeach' ? 'setup' : 'test';
            $first = self::significant($tokens, $opening, 1);
            $name = null;
            if ($kind === 'test') {
                if (($tokens[$first]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $literal = $tokens[$first]['text'];
                $name = $literal[0] === "'"
                    ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($literal, 1, -1))
                    : stripcslashes(substr($literal, 1, -1));
            }
            $callback = null;
            for ($cursor = $first; $cursor < $closing; $cursor++) {
                if ($tokens[$cursor]['id'] === T_FUNCTION) {
                    // Skip parameters and use(...) before locating the callback body.
                    for ($body = $cursor + 1; $body < $closing; $body++) {
                        if ($tokens[$body]['id'] === null && $tokens[$body]['text'] === '{') {
                            $callback = [$body, $pairs[$body] ?? $closing];
                            break 2;
                        }
                    }
                } elseif ($tokens[$cursor]['id'] === T_FN) {
                    $callback = [$cursor, $closing];
                    break;
                }
            }
            if ($callback === null || $line < $tokens[$callback[0]]['line'] || $line > $tokens[$callback[1]]['line']) {
                continue;
            }
            $annotation = null;
            for ($cursor = $callback[0]; $cursor < $callback[1]; $cursor++) {
                $candidate = $tokens[$cursor];
                if ($candidate['line'] > $line) {
                    break;
                }
                // Completed nested callbacks cannot donate annotations to the caller.
                if ($candidate['text'] === '{' && isset($pairs[$cursor]) && $tokens[$pairs[$cursor]]['line'] < $line) {
                    $cursor = $pairs[$cursor];

                    continue;
                }
                if ($candidate['id'] === T_DOC_COMMENT && preg_match('/@(Given|When|Then|And|But|\*)\s+(.*?)\s*\*\//is', $candidate['text'], $match)) {
                    $annotation = [ucfirst(strtolower($match[1])), trim(preg_replace('/\R\h*\*\h?/', ' ', $match[2]))];
                }
            }
            $contexts[] = ['kind' => $kind, 'name' => $name, 'line' => $token['line'], 'annotation' => $annotation, 'size' => $closing - $opening];
        }
        usort($contexts, static fn (array $a, array $b): int => $a['size'] <=> $b['size']);

        return $contexts[0] ?? null;
    }

    private static function significant(array $tokens, int $index, int $direction): int
    {
        do {
            $index += $direction;
        } while (isset($tokens[$index]) && in_array($tokens[$index]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));

        return $index;
    }
}
