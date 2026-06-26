<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown\Contracts;

use PhpSoftBox\Markdown\MarkdownDocument;
use PhpSoftBox\Markdown\MarkdownRenderOptions;

interface MarkdownRendererInterface
{
    public function render(string $source, ?MarkdownRenderOptions $options = null): MarkdownDocument;
}
