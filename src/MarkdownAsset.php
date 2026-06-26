<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownAsset
{
    public function __construct(
        private string $target,
        private string $url,
        private bool $resolved,
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
}
