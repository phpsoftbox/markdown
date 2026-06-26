<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

use PhpSoftBox\Markdown\Contracts\MarkdownSluggerInterface;

use function mb_strtolower;
use function preg_replace;
use function trim;

final class DefaultMarkdownSlugger implements MarkdownSluggerInterface
{
    public function slug(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = (string) preg_replace('~[^\pL\pN]+~u', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'section';
    }
}
