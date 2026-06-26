<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownSource
{
    /**
     * @param array<string, mixed> $frontMatter
     * @param list<MarkdownDiagnostic> $diagnostics
     */
    public function __construct(
        private array $frontMatter,
        private string $body,
        private array $diagnostics = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function frontMatter(): array
    {
        return $this->frontMatter;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return list<MarkdownDiagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
