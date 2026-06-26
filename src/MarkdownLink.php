<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownLink
{
    public function __construct(
        private string $target,
        private string $url,
        private bool $resolved,
        private bool $external,
    ) {
    }

    public function target(): string
    {
        return $this->target;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function resolved(): bool
    {
        return $this->resolved;
    }

    public function external(): bool
    {
        return $this->external;
    }
}
