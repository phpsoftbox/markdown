<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownDocument
{
    /**
     * @param array<string, mixed> $frontMatter
     * @param list<MarkdownHeading> $headings
     * @param list<MarkdownLink> $links
     * @param list<MarkdownAsset> $assets
     * @param list<MarkdownDiagnostic> $diagnostics
     */
    public function __construct(
        private string $html,
        private array $frontMatter,
        private MarkdownToc $toc,
        private array $headings = [],
        private array $links = [],
        private array $assets = [],
        private array $diagnostics = [],
    ) {
    }

    public function html(): string
    {
        return $this->html;
    }

    /**
     * @return array<string, mixed>
     */
    public function frontMatter(): array
    {
        return $this->frontMatter;
    }

    public function toc(): MarkdownToc
    {
        return $this->toc;
    }

    /**
     * @return list<MarkdownHeading>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<MarkdownLink>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @return list<MarkdownAsset>
     */
    public function assets(): array
    {
        return $this->assets;
    }

    /**
     * @return list<MarkdownDiagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
