<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

use PhpSoftBox\Markdown\Contracts\MarkdownLinkResolverInterface;

final readonly class MarkdownRenderOptions
{
    /**
     * @param list<string> $allowedAdmonitions
     * @param list<string> $allowedCodeLanguages
     */
    public function __construct(
        public MarkdownHtmlPolicy $htmlPolicy = MarkdownHtmlPolicy::Escape,
        public int $tocMinHeadingLevel = 2,
        public int $tocMaxHeadingLevel = 3,
        public ?string $basePath = null,
        public ?string $currentDocumentPath = null,
        public ?MarkdownLinkResolverInterface $linkResolver = null,
        public array $allowedAdmonitions = ['note', 'tip', 'info', 'warning', 'danger'],
        public ?string $externalLinkTarget = null,
        public bool $externalLinksNoFollow = false,
        public array $allowedCodeLanguages = [],
    ) {
    }
}
