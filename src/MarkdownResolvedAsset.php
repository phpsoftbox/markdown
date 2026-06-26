<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownResolvedAsset
{
    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     */
    public function __construct(
        private string $url,
        private bool $resolved = true,
        private array $diagnostics = [],
    ) {
    }

    public static function resolved(string $url): self
    {
        return new self($url);
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     */
    public static function unresolved(string $target, array $diagnostics = []): self
    {
        return new self($target, false, $diagnostics);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * @return list<MarkdownDiagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
