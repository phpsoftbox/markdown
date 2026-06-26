<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown\Contracts;

interface MarkdownSluggerInterface
{
    public function slug(string $text): string;
}
