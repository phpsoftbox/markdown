<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown\Contracts;

use PhpSoftBox\Markdown\MarkdownRenderContext;
use PhpSoftBox\Markdown\MarkdownResolvedAsset;
use PhpSoftBox\Markdown\MarkdownResolvedLink;

interface MarkdownLinkResolverInterface
{
    public function resolveLink(string $target, MarkdownRenderContext $context): MarkdownResolvedLink;

    public function resolveAsset(string $target, MarkdownRenderContext $context): MarkdownResolvedAsset;
}
