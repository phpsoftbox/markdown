<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownTocItem
{
    public function __construct(
        private int $level,
        private string $title,
        private string $id,
    ) {
    }

    public function level(): int
    {
        return $this->level;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function id(): string
    {
        return $this->id;
    }
}
