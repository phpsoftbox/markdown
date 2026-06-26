<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownRenderContext
{
    /**
     * @param array<string, mixed> $frontMatter
     */
    public function __construct(
        private ?string $basePath,
        private ?string $currentDocumentPath,
        private array $frontMatter,
        private MarkdownRenderOptions $options,
    ) {
    }

    public function basePath(): ?string
    {
        return $this->basePath;
    }

    public function currentDocumentPath(): ?string
    {
        return $this->currentDocumentPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function frontMatter(): array
    {
        return $this->frontMatter;
    }

    public function options(): MarkdownRenderOptions
    {
        return $this->options;
    }
}
