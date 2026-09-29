<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use PhpSoftBox\Markdown\Contracts\FrontMatterParserInterface;
use PhpSoftBox\Markdown\Contracts\MarkdownLinkResolverInterface;
use PhpSoftBox\Markdown\Contracts\MarkdownRendererInterface;
use PhpSoftBox\Markdown\Contracts\MarkdownSluggerInterface;

use function array_key_exists;
use function array_merge;
use function count;
use function htmlspecialchars;
use function implode;
use function in_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stripos;
use function trim;

use const ENT_QUOTES;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_NONET;

final class MarkdownRenderer implements MarkdownRendererInterface
{
    public function __construct(
        private readonly FrontMatterParserInterface $frontMatterParser = new YamlFrontMatterParser(),
        private readonly MarkdownSluggerInterface $slugger = new DefaultMarkdownSlugger(),
        private readonly MarkdownLinkResolverInterface $defaultLinkResolver = new PassthroughMarkdownLinkResolver(),
    ) {
    }

    public function render(string $source, ?MarkdownRenderOptions $options = null): MarkdownDocument
    {
        $options ??= new MarkdownRenderOptions();

        $parsed      = $this->frontMatterParser->parse($source);
        $diagnostics = $parsed->diagnostics();
        $context     = new MarkdownRenderContext(
            $options->basePath,
            $options->currentDocumentPath,
            $parsed->frontMatter(),
            $options,
        );

        $body                = $parsed->body();
        [$body, $codeBlocks] = $this->normalizeCodeFenceInfo($body, $options, $diagnostics);

        if ($options->htmlPolicy !== MarkdownHtmlPolicy::Allow) {
            $this->collectRawHtmlDiagnostics($body, $diagnostics);
        } else {
            $body = $this->markRawHtmlHeadings($body);
        }

        [$body, $tabs]        = $this->extractTabs($body, $diagnostics);
        [$body, $admonitions] = $this->extractAdmonitions($body, $options, $diagnostics);

        $html = $this->convertMarkdownToHtml($body, $options);
        $html = $this->replaceAdmonitions($html, $admonitions, $options);
        $html = $this->replaceTabs($html, $tabs, $options);

        return $this->postProcessHtml($html, $parsed->frontMatter(), $context, $options, $codeBlocks, $diagnostics);
    }

    private function convertMarkdownToHtml(string $body, MarkdownRenderOptions $options): string
    {
        $environment = new Environment([
            'html_input'         => $options->htmlPolicy->value,
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return (string) new MarkdownConverter($environment)->convert($body);
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     */
    private function collectRawHtmlDiagnostics(string $body, array &$diagnostics): void
    {
        $lines       = preg_split('~\R~u', $body) ?: [];
        $insideFence = false;
        $fence       = '';

        foreach ($lines as $index => $line) {
            if (preg_match('#^(`{3,}|~{3,})#', $line, $matches) === 1) {
                if (!$insideFence) {
                    $insideFence = true;
                    $fence       = $matches[1];
                } elseif (str_starts_with($line, $fence)) {
                    $insideFence = false;
                    $fence       = '';
                }

                continue;
            }

            if ($insideFence) {
                continue;
            }

            if (preg_match('~</?[A-Za-z][A-Za-z0-9:-]*(?:\s[^>]*)?/?>~', $line) !== 1) {
                continue;
            }

            $diagnostics[] = new MarkdownDiagnostic(
                MarkdownDiagnosticLevel::Warning,
                'html.disallowed',
                'Raw HTML is disabled for this Markdown document.',
                $index + 1,
            );
        }
    }

    private function markRawHtmlHeadings(string $body): string
    {
        return (string) preg_replace(
            '~<h([1-6])(\s[^>]*)?>~i',
            '<h$1$2 data-markdown-raw-heading="1">',
            $body,
        );
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     * @return array{0:string,1:list<array{language:string,title:?string}>}
     */
    private function normalizeCodeFenceInfo(string $body, MarkdownRenderOptions $options, array &$diagnostics): array
    {
        $lines      = preg_split('~\R~u', $body) ?: [];
        $normalized = [];
        $codeBlocks = [];
        $inside     = false;
        $fence      = '';

        foreach ($lines as $lineNumber => $line) {
            if (!$inside && preg_match('#^(`{3,}|~{3,})(.*)$#', $line, $matches) === 1) {
                $inside = true;
                $fence  = $matches[1];
                $meta   = $this->parseCodeFenceMeta($matches[2]);

                if (
                    $meta['language'] !== ''
                    && $options->allowedCodeLanguages !== []
                    && !in_array($meta['language'], $options->allowedCodeLanguages, true)
                ) {
                    $diagnostics[] = new MarkdownDiagnostic(
                        MarkdownDiagnosticLevel::Warning,
                        'code.language_unknown',
                        sprintf('Unknown code block language: %s.', $meta['language']),
                        $lineNumber + 1,
                    );
                }

                $codeBlocks[] = $meta;
                $normalized[] = $fence . ($meta['language'] !== '' ? ' ' . $meta['language'] : '');

                continue;
            }

            if ($inside && str_starts_with($line, $fence)) {
                $inside = false;
                $fence  = '';
            }

            $normalized[] = $line;
        }

        return [implode("\n", $normalized), $codeBlocks];
    }

    /**
     * @return array{language:string,title:?string}
     */
    private function parseCodeFenceMeta(string $info): array
    {
        $title = null;
        if (preg_match('~\btitle=(["\'])(.*?)\1~', $info, $matches) === 1) {
            $title = $matches[2];
        }

        $info  = (string) preg_replace('~\s*\btitle=(["\']).*?\1\s*~', ' ', $info);
        $parts = preg_split('~\s+~', trim($info)) ?: [];

        return [
            'language' => trim((string) ($parts[0] ?? ''), ".{} \t\n\r\0\x0B"),
            'title'    => $title,
        ];
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     * @return array{0:string,1:list<array{token:string,tabs:list<array{title:string,content:string}>}>}
     */
    private function extractTabs(string $body, array &$diagnostics): array
    {
        $lines  = preg_split('~\R~u', $body) ?: [];
        $output = [];
        $tabs   = [];
        $count  = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) !== ':::tabs') {
                $output[] = $line;
                continue;
            }

            $contentLines = [];
            $endIndex     = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (trim($lines[$j]) === ':::') {
                    $endIndex = $j;
                    break;
                }

                $contentLines[] = $lines[$j];
            }

            if ($endIndex === null) {
                $diagnostics[] = new MarkdownDiagnostic(
                    MarkdownDiagnosticLevel::Warning,
                    'tabs.invalid',
                    'Tabs block is not closed.',
                    $i + 1,
                );
                $output[] = $line;
                continue;
            }

            $parsedTabs = $this->parseTabsContent($contentLines);
            if ($parsedTabs === []) {
                $diagnostics[] = new MarkdownDiagnostic(
                    MarkdownDiagnosticLevel::Warning,
                    'tabs.invalid',
                    'Tabs block must contain at least one @tab section.',
                    $i + 1,
                );
                $i = $endIndex;
                continue;
            }

            $token  = $this->placeholder('TABS', count($tabs));
            $tabs[] = [
                'token' => $token,
                'tabs'  => $parsedTabs,
            ];
            $output[] = $token;
            $i        = $endIndex;
        }

        return [implode("\n", $output), $tabs];
    }

    /**
     * @param list<string> $lines
     * @return list<array{title:string,content:string}>
     */
    private function parseTabsContent(array $lines): array
    {
        $tabs         = [];
        $currentTitle = null;
        $currentLines = [];
        $insideFence  = false;
        $fence        = '';

        foreach ($lines as $offset => $line) {
            if (preg_match('#^(`{3,}|~{3,})#', $line, $matches) === 1) {
                if (!$insideFence) {
                    $insideFence = true;
                    $fence       = $matches[1];
                } elseif (str_starts_with($line, $fence)) {
                    $insideFence = false;
                    $fence       = '';
                }
            }

            if (!$insideFence && preg_match('~^@tab\s+(.+?)\s*$~', $line, $matches) === 1) {
                if ($currentTitle !== null) {
                    $tabs[] = [
                        'title'   => $currentTitle,
                        'content' => trim(implode("\n", $currentLines)),
                    ];
                }

                $currentTitle = trim($matches[1]);
                $currentLines = [];

                continue;
            }

            if ($currentTitle === null) {
                continue;
            }

            $currentLines[] = $line;
        }

        if ($currentTitle !== null) {
            $tabs[] = [
                'title'   => $currentTitle,
                'content' => trim(implode("\n", $currentLines)),
            ];
        }

        return $tabs;
    }

    /**
     * @param list<array{token:string,tabs:list<array{title:string,content:string}>}> $tabs
     */
    private function replaceTabs(string $html, array $tabs, MarkdownRenderOptions $options): string
    {
        foreach ($tabs as $tabBlock) {
            $replacement = $this->renderTabs($tabBlock['tabs'], $options);
            $html        = str_replace('<p>' . $tabBlock['token'] . '</p>', $replacement, $html);
            $html        = str_replace($tabBlock['token'], $replacement, $html);
        }

        return $html;
    }

    /**
     * @param list<array{title:string,content:string}> $tabs
     */
    private function renderTabs(array $tabs, MarkdownRenderOptions $options): string
    {
        $ids        = [];
        $navHtml    = '';
        $panelsHtml = '';

        foreach ($tabs as $index => $tab) {
            $baseId = $this->slugger->slug($tab['title']);
            $id     = $baseId;
            if (array_key_exists($baseId, $ids)) {
                $ids[$baseId]++;
                $id = $baseId . '-' . $ids[$baseId];
            } else {
                $ids[$baseId] = 1;
            }

            $isActive = $index === 0;
            $navHtml .= '<button type="button" class="markdown-tabs__tab'
                . ($isActive ? ' markdown-tabs__tab--active' : '')
                . '" role="tab" data-tab="' . htmlspecialchars($id, ENT_QUOTES) . '" aria-selected="'
                . ($isActive ? 'true' : 'false')
                . '">'
                . htmlspecialchars($tab['title'], ENT_QUOTES)
                . '</button>';

            $panelsHtml .= '<div class="markdown-tabs__panel'
                . ($isActive ? ' markdown-tabs__panel--active' : '')
                . '" role="tabpanel" data-tab="' . htmlspecialchars($id, ENT_QUOTES) . '">'
                . $this->convertMarkdownToHtml($tab['content'], $options)
                . '</div>';
        }

        return '<div class="markdown-tabs"><div class="markdown-tabs__nav" role="tablist">'
            . $navHtml
            . '</div><div class="markdown-tabs__panels">'
            . $panelsHtml
            . '</div></div>';
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     * @return array{0:string,1:list<array{token:string,type:string,content:string}>}
     */
    private function extractAdmonitions(string $body, MarkdownRenderOptions $options, array &$diagnostics): array
    {
        $lines       = preg_split('~\R~u', $body) ?: [];
        $output      = [];
        $admonitions = [];
        $count       = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('~^:::(\w+)\s*$~', $line, $matches) !== 1) {
                $output[] = $line;
                continue;
            }

            $type         = mb_strtolower($matches[1]);
            $contentLines = [];
            $endIndex     = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (trim($lines[$j]) === ':::') {
                    $endIndex = $j;
                    break;
                }

                $contentLines[] = $lines[$j];
            }

            if ($endIndex === null || !in_array($type, $options->allowedAdmonitions, true)) {
                $diagnostics[] = new MarkdownDiagnostic(
                    MarkdownDiagnosticLevel::Warning,
                    'admonition.unknown',
                    sprintf('Unknown or invalid admonition: %s.', $type),
                    $i + 1,
                );
                $output[] = $line;
                continue;
            }

            $token         = $this->placeholder('ADMONITION', count($admonitions));
            $admonitions[] = [
                'token'   => $token,
                'type'    => $type,
                'content' => implode("\n", $contentLines),
            ];
            $output[] = $token;
            $i        = $endIndex;
        }

        return [implode("\n", $output), $admonitions];
    }

    /**
     * @param list<array{token:string,type:string,content:string}> $admonitions
     */
    private function replaceAdmonitions(string $html, array $admonitions, MarkdownRenderOptions $options): string
    {
        foreach ($admonitions as $admonition) {
            $replacement = $this->renderAdmonition($admonition['type'], $admonition['content'], $options);
            $html        = str_replace('<p>' . $admonition['token'] . '</p>', $replacement, $html);
            $html        = str_replace($admonition['token'], $replacement, $html);
        }

        return $html;
    }

    private function renderAdmonition(string $type, string $content, MarkdownRenderOptions $options): string
    {
        $contentHtml = $this->convertMarkdownToHtml($content, $options);
        $title       = match ($type) {
            'tip'     => 'Tip',
            'info'    => 'Info',
            'warning' => 'Warning',
            'danger'  => 'Danger',
            default   => 'Note',
        };

        return '<div class="markdown-admonition markdown-admonition--' . htmlspecialchars($type, ENT_QUOTES) . '" data-type="'
            . htmlspecialchars($type, ENT_QUOTES)
            . '"><div class="markdown-admonition__title">'
            . htmlspecialchars($title, ENT_QUOTES)
            . '</div><div class="markdown-admonition__content">'
            . $contentHtml
            . '</div></div>';
    }

    /**
     * @param array<string, mixed> $frontMatter
     * @param list<array{language:string,title:?string}> $codeBlocks
     * @param list<MarkdownDiagnostic> $diagnostics
     */
    private function postProcessHtml(
        string $html,
        array $frontMatter,
        MarkdownRenderContext $context,
        MarkdownRenderOptions $options,
        array $codeBlocks,
        array $diagnostics,
    ): MarkdownDocument {
        [$dom, $root] = $this->loadHtmlFragment($html);
        if (!$root instanceof DOMElement) {
            return new MarkdownDocument($html, $frontMatter, new MarkdownToc(), diagnostics: $diagnostics);
        }

        $xpath = new DOMXPath($dom);

        [$headings, $toc, $diagnostics] = $this->processHeadings($xpath, $root, $options, $diagnostics);
        [$links, $diagnostics]          = $this->processLinks($xpath, $root, $context, $options, $diagnostics);
        [$assets, $diagnostics]         = $this->processAssets($xpath, $root, $context, $options, $diagnostics);

        $this->applyCodeBlockMeta($xpath, $root, $codeBlocks);

        return new MarkdownDocument(
            $this->innerHtml($dom, $root),
            $frontMatter,
            $toc,
            $headings,
            $links,
            $assets,
            $diagnostics,
        );
    }

    /**
     * @return array{0:DOMDocument,1:?DOMElement}
     */
    private function loadHtmlFragment(string $html): array
    {
        $dom      = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="psb-markdown-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return [$dom, $dom->getElementById('psb-markdown-root')];
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     * @return array{0:list<MarkdownHeading>,1:MarkdownToc,2:list<MarkdownDiagnostic>}
     */
    private function processHeadings(DOMXPath $xpath, DOMElement $root, MarkdownRenderOptions $options, array $diagnostics): array
    {
        $headings = [];
        $tocItems = [];
        $ids      = [];

        $nodes = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $root);
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->hasAttribute('data-markdown-raw-heading')) {
                $node->removeAttribute('data-markdown-raw-heading');
                continue;
            }

            $level = (int) $node->tagName[1];
            $title = trim($node->textContent);
            $base  = $this->slugger->slug($title);
            $id    = $base;
            if (array_key_exists($id, $ids)) {
                $ids[$base]++;
                $id            = $base . '-' . $ids[$base];
                $diagnostics[] = new MarkdownDiagnostic(
                    MarkdownDiagnosticLevel::Info,
                    'heading.duplicate_id',
                    sprintf('Duplicate heading id resolved as "%s".', $id),
                );
            } else {
                $ids[$base] = 1;
            }

            $node->setAttribute('id', $id);
            $heading = new MarkdownHeading($level, $title, $id);

            $headings[] = $heading;

            if ($level >= $options->tocMinHeadingLevel && $level <= $options->tocMaxHeadingLevel) {
                $tocItems[] = new MarkdownTocItem($level, $title, $id);
            }
        }

        return [$headings, new MarkdownToc($tocItems), $diagnostics];
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     * @return array{0:list<MarkdownLink>,1:list<MarkdownDiagnostic>}
     */
    private function processLinks(
        DOMXPath $xpath,
        DOMElement $root,
        MarkdownRenderContext $context,
        MarkdownRenderOptions $options,
        array $diagnostics,
    ): array {
        $links    = [];
        $resolver = $options->linkResolver ?? $this->defaultLinkResolver;
        $nodes    = $xpath->query('.//a[@href]', $root);

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $target = $node->getAttribute('href');
            if ($this->isDangerousUrl($target)) {
                $node->setAttribute('href', '#');
                $diagnostics[] = new MarkdownDiagnostic(
                    MarkdownDiagnosticLevel::Warning,
                    'link.unresolved',
                    sprintf('Dangerous link URL was blocked: %s.', $target),
                );
                $links[] = new MarkdownLink($target, '#', false, false);
                continue;
            }

            $resolved    = $resolver->resolveLink($target, $context);
            $diagnostics = $this->mergeResolvedDiagnostics(
                $diagnostics,
                $resolved->diagnostics(),
                'link.unresolved',
                $target,
                $resolved->isResolved(),
            );
            $url      = $resolved->url();
            $external = $this->isExternalUrl($url);

            $node->setAttribute('href', $url);
            if ($external && $options->externalLinkTarget !== null) {
                $node->setAttribute('target', $options->externalLinkTarget);
            }
            if ($external && $options->externalLinksNoFollow) {
                $node->setAttribute('rel', 'nofollow noopener noreferrer');
            }

            $links[] = new MarkdownLink($target, $url, $resolved->isResolved(), $external);
        }

        return [$links, $diagnostics];
    }

    /**
     * @param list<MarkdownDiagnostic> $diagnostics
     * @return array{0:list<MarkdownAsset>,1:list<MarkdownDiagnostic>}
     */
    private function processAssets(
        DOMXPath $xpath,
        DOMElement $root,
        MarkdownRenderContext $context,
        MarkdownRenderOptions $options,
        array $diagnostics,
    ): array {
        $assets   = [];
        $resolver = $options->linkResolver ?? $this->defaultLinkResolver;
        $nodes    = $xpath->query('.//img[@src]', $root);

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $target = $node->getAttribute('src');
            if ($this->isDangerousUrl($target)) {
                $node->setAttribute('src', '');
                $diagnostics[] = new MarkdownDiagnostic(
                    MarkdownDiagnosticLevel::Warning,
                    'asset.unresolved',
                    sprintf('Dangerous asset URL was blocked: %s.', $target),
                );
                $assets[] = new MarkdownAsset($target, '', false);
                continue;
            }

            $resolved    = $resolver->resolveAsset($target, $context);
            $diagnostics = $this->mergeResolvedDiagnostics(
                $diagnostics,
                $resolved->diagnostics(),
                'asset.unresolved',
                $target,
                $resolved->isResolved(),
            );
            $url = $resolved->url();

            $node->setAttribute('src', $url);
            $assets[] = new MarkdownAsset($target, $url, $resolved->isResolved());
        }

        return [$assets, $diagnostics];
    }

    /**
     * @param list<MarkdownDiagnostic> $current
     * @param list<MarkdownDiagnostic> $resolved
     * @return list<MarkdownDiagnostic>
     */
    private function mergeResolvedDiagnostics(
        array $current,
        array $resolved,
        string $defaultCode,
        string $target,
        bool $isResolved,
    ): array {
        if ($resolved !== []) {
            return array_merge($current, $resolved);
        }

        if ($isResolved) {
            return $current;
        }

        $current[] = new MarkdownDiagnostic(
            MarkdownDiagnosticLevel::Warning,
            $defaultCode,
            sprintf('Markdown target could not be resolved: %s.', $target),
        );

        return $current;
    }

    /**
     * @param list<array{language:string,title:?string}> $codeBlocks
     */
    private function applyCodeBlockMeta(DOMXPath $xpath, DOMElement $root, array $codeBlocks): void
    {
        $nodes     = $xpath->query('.//pre/code', $root);
        $codeNodes = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $codeNodes[] = $node;
            }
        }

        foreach ($codeNodes as $index => $codeNode) {
            if (!isset($codeBlocks[$index])) {
                continue;
            }

            $meta = $codeBlocks[$index];
            $pre  = $codeNode->parentNode;
            if (!$pre instanceof DOMElement || $pre->tagName !== 'pre') {
                continue;
            }

            if ($meta['language'] !== '') {
                $pre->setAttribute('data-language', $meta['language']);
            }
            if ($meta['title'] === null || $meta['title'] === '') {
                continue;
            }

            $pre->setAttribute('data-title', $meta['title']);
            $this->wrapCodeBlockWithTitle($pre, $meta['title']);
        }
    }

    private function wrapCodeBlockWithTitle(DOMElement $pre, string $title): void
    {
        $parent = $pre->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }

        $dom = $pre->ownerDocument;
        if (!$dom instanceof DOMDocument) {
            return;
        }

        $figure = $dom->createElement('figure');
        $figure->setAttribute('class', 'markdown-code');

        $caption = $dom->createElement('figcaption');
        $caption->setAttribute('class', 'markdown-code__title');
        $caption->appendChild($dom->createTextNode($title));

        $parent->replaceChild($figure, $pre);
        $figure->appendChild($caption);
        $figure->appendChild($pre);
    }

    /**
     * Метка блока с завершающим суффиксом: без него замена `..._1` задела бы `..._10`.
     */
    private function placeholder(string $kind, int $index): string
    {
        return sprintf('PSB_MARKDOWN_%s_%d_END', $kind, $index);
    }

    private function isDangerousUrl(string $url): bool
    {
        $normalized = mb_strtolower(trim($url));

        return str_starts_with($normalized, 'javascript:')
            || str_starts_with($normalized, 'vbscript:')
            || str_starts_with($normalized, 'data:');
    }

    private function isExternalUrl(string $url): bool
    {
        return str_contains($url, '://') || str_starts_with($url, '//') || stripos($url, 'mailto:') === 0;
    }

    private function innerHtml(DOMDocument $dom, DOMElement $root): string
    {
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }
}
