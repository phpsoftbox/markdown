<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown\Contracts;

use PhpSoftBox\Markdown\MarkdownSource;

interface FrontMatterParserInterface
{
    public function parse(string $source): MarkdownSource;
}
