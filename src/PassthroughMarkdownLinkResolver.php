<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

use PhpSoftBox\Markdown\Contracts\MarkdownLinkResolverInterface;

final class PassthroughMarkdownLinkResolver implements MarkdownLinkResolverInterface
{
    public function resolveLink(string $target, MarkdownRenderContext $context): MarkdownResolvedLink
    {
        return MarkdownResolvedLink::resolved($target);
    }

    public function resolveAsset(string $target, MarkdownRenderContext $context): MarkdownResolvedAsset
    {
        return MarkdownResolvedAsset::resolved($target);
    }
}
