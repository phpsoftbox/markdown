<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownToc
{
    /**
     * @param list<MarkdownTocItem> $items
     */
    public function __construct(
        private array $items = [],
    ) {
    }

    /**
     * @return list<MarkdownTocItem>
     */
    public function items(): array
    {
        return $this->items;
    }
}
