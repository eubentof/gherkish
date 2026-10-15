<?php

declare(strict_types=1);

namespace Gherkish\Gherkin;

/** Internal, source-located syntax node shared by all feature consumers. */
final class Node
{
    public array $children = [];

    public array $steps = [];

    public array $tags = [];

    public array $rows = [];

    public array $rowLines = [];

    public string $description = '';

    public ?Node $table = null;

    public ?Node $docString = null;

    public string $content = '';

    public ?string $contentType = null;

    public function __construct(
        public string $kind,
        public string $keyword,
        public string $name,
        public int $line,
        public int $column,
    ) {}
}
