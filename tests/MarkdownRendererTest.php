<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown\Tests;

use PhpSoftBox\Markdown\Contracts\MarkdownLinkResolverInterface;
use PhpSoftBox\Markdown\MarkdownDiagnostic;
use PhpSoftBox\Markdown\MarkdownDiagnosticLevel;
use PhpSoftBox\Markdown\MarkdownDocument;
use PhpSoftBox\Markdown\MarkdownHtmlPolicy;
use PhpSoftBox\Markdown\MarkdownRenderContext;
use PhpSoftBox\Markdown\MarkdownRenderer;
use PhpSoftBox\Markdown\MarkdownRenderOptions;
use PhpSoftBox\Markdown\MarkdownResolvedAsset;
use PhpSoftBox\Markdown\MarkdownResolvedLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(MarkdownRenderer::class)]
final class MarkdownRendererTest extends TestCase
{
    /**
     * Проверяем, что raw HTML по умолчанию экранируется и попадает в diagnostics.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testEscapesRawHtmlByDefault(): void
    {
        $document = new MarkdownRenderer()->render("Hello\n\n<div>raw</div>");

        $this->assertStringContainsString('&lt;div&gt;raw&lt;/div&gt;', $document->html());
        $this->assertContains('html.disallowed', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем, что HTML/JSX-похожие примеры внутри fenced code block не считаются raw HTML.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testRawHtmlDiagnosticsSkipFencedCodeBlocks(): void
    {
        $source = <<<'MD'
```mdx
<Hero title="Docs" />
```

<div>raw</div>
MD;

        $document = new MarkdownRenderer()->render($source);

        $this->assertStringContainsString('&lt;Hero title="Docs" /&gt;', $document->html());
        $this->assertStringContainsString('&lt;div&gt;raw&lt;/div&gt;', $document->html());
        $this->assertSame(['html.disallowed'], $this->diagnosticCodes($document));
    }

    /**
     * Проверяем стабильные id заголовков, suffix для дублей и фильтрацию TOC по уровню.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testBuildsHeadingIdsAndToc(): void
    {
        $source = <<<MD
# Root
## Install
### Install
#### Hidden
## Install
MD;

        $document = new MarkdownRenderer()->render($source, new MarkdownRenderOptions(
            tocMinHeadingLevel: 2,
            tocMaxHeadingLevel: 3,
        ));

        $this->assertSame(['root', 'install', 'install-2', 'hidden', 'install-3'], array_map(
            static fn ($heading): string => $heading->id(),
            $document->headings(),
        ));
        $this->assertSame(['install', 'install-2', 'install-3'], array_map(
            static fn ($item): string => $item->id(),
            $document->toc()->items(),
        ));
        $this->assertContains('heading.duplicate_id', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем, что TOC не включает заголовки из raw HTML.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testTocSkipsRawHtmlHeadings(): void
    {
        $document = new MarkdownRenderer()->render(
            "## Markdown\n\n<h2>Raw HTML</h2>",
            new MarkdownRenderOptions(htmlPolicy: MarkdownHtmlPolicy::Allow, tocMinHeadingLevel: 2, tocMaxHeadingLevel: 2),
        );

        $this->assertStringContainsString('<h2>Raw HTML</h2>', $document->html());
        $this->assertSame(['markdown'], array_map(
            static fn ($item): string => $item->id(),
            $document->toc()->items(),
        ));
    }

    /**
     * Проверяем language/title у fenced code blocks и diagnostic для неизвестного языка.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testRendersCodeBlockLanguageAndTitle(): void
    {
        $source = <<<'MD'
```php title="app.php"
echo 1;
```
MD;

        $document = new MarkdownRenderer()->render($source, new MarkdownRenderOptions(
            allowedCodeLanguages: ['js'],
        ));

        $this->assertStringContainsString('data-language="php"', $document->html());
        $this->assertStringContainsString('data-title="app.php"', $document->html());
        $this->assertStringContainsString('markdown-code__title', $document->html());
        $this->assertContains('code.language_unknown', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем, что построчная обработка Markdown не режет UTF-8 символы по байтам.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testPreservesUtf8CharactersWhileSplittingLines(): void
    {
        $source = <<<MD
# Что такое E-Doc

E-Doc - это простой self-hosted хостинг документации.

:::note
Хороший раздел с русским текстом.
:::
MD;

        $document = new MarkdownRenderer()->render($source);

        $this->assertStringContainsString('Что такое E-Doc', $document->html());
        $this->assertStringContainsString('self-hosted хостинг', $document->html());
        $this->assertStringContainsString('Хороший раздел', $document->html());
    }

    /**
     * Проверяем поддержанные admonition-блоки.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testRendersSupportedAdmonitions(): void
    {
        $source = <<<MD
:::note
Note text
:::

:::tip
Tip text
:::

:::info
Info text
:::

:::warning
Warning text
:::

:::danger
Danger text
:::
MD;

        $document = new MarkdownRenderer()->render($source);

        $html = $document->html();

        foreach (['note', 'tip', 'info', 'warning', 'danger'] as $type) {
            $this->assertStringContainsString('markdown-admonition--' . $type, $html);
        }
        $this->assertNotContains('admonition.unknown', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем diagnostic для неизвестного admonition-блока.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testReturnsDiagnosticForUnknownAdmonition(): void
    {
        $document = new MarkdownRenderer()->render(":::custom\nText\n:::");

        $this->assertContains('admonition.unknown', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем рендер tabs-блока с markdown-контентом внутри вкладок.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testRendersTabs(): void
    {
        $source = <<<MD
:::tabs
@tab PHP
**PHP** content

@tab JavaScript
`JS` content
:::
MD;

        $document = new MarkdownRenderer()->render($source);

        $html = $document->html();

        $this->assertStringContainsString('class="markdown-tabs"', $html);
        $this->assertStringContainsString('class="markdown-tabs__tab markdown-tabs__tab--active"', $html);
        $this->assertStringContainsString('data-tab="php"', $html);
        $this->assertStringContainsString('data-tab="javascript"', $html);
        $this->assertStringContainsString('<strong>PHP</strong> content', $html);
        $this->assertStringContainsString('<code>JS</code> content', $html);
    }

    /**
     * Проверяем diagnostic для некорректного tabs-блока.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testReturnsDiagnosticForInvalidTabs(): void
    {
        $document = new MarkdownRenderer()->render(":::tabs\nNo tabs\n:::");

        $this->assertContains('tabs.invalid', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем, что JSX-похожие теги в .md считаются обычным raw HTML.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testTreatsComponentLikeTagsAsRawHtml(): void
    {
        $source = <<<'MD'
<Hero title="Docs">
Hero content.
</Hero>
MD;

        $escaped = new MarkdownRenderer()->render($source);
        $allowed = new MarkdownRenderer()->render($source, new MarkdownRenderOptions(
            htmlPolicy: MarkdownHtmlPolicy::Allow,
        ));

        $this->assertStringContainsString('&lt;Hero title="Docs"&gt;', $escaped->html());
        $this->assertContains('html.disallowed', $this->diagnosticCodes($escaped));
        $this->assertStringContainsString('<hero title="Docs">', $allowed->html());
        $this->assertNotContains('html.disallowed', $this->diagnosticCodes($allowed));
    }

    /**
     * Проверяем resolver для ссылок и assets, включая unresolved diagnostics.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testResolvesLinksAndAssets(): void
    {
        $resolver = new class () implements MarkdownLinkResolverInterface {
            public function resolveLink(string $target, MarkdownRenderContext $context): MarkdownResolvedLink
            {
                if ($target === './ok.md') {
                    return MarkdownResolvedLink::resolved('/docs/ok');
                }

                return MarkdownResolvedLink::unresolved($target, [
                    new MarkdownDiagnostic(MarkdownDiagnosticLevel::Warning, 'link.unresolved', 'Link not found.'),
                ]);
            }

            public function resolveAsset(string $target, MarkdownRenderContext $context): MarkdownResolvedAsset
            {
                if ($target === './ok.png') {
                    return MarkdownResolvedAsset::resolved('/assets/ok.png');
                }

                return MarkdownResolvedAsset::unresolved($target, [
                    new MarkdownDiagnostic(MarkdownDiagnosticLevel::Warning, 'asset.unresolved', 'Asset not found.'),
                ]);
            }
        };

        $document = new MarkdownRenderer()->render(
            '[ok](./ok.md) [bad](./bad.md) ![ok](./ok.png) ![bad](./missing.png)',
            new MarkdownRenderOptions(linkResolver: $resolver),
        );

        $this->assertStringContainsString('href="/docs/ok"', $document->html());
        $this->assertStringContainsString('src="/assets/ok.png"', $document->html());
        $this->assertCount(2, $document->links());
        $this->assertTrue($document->links()[0]->resolved());
        $this->assertFalse($document->links()[1]->resolved());
        $this->assertCount(2, $document->assets());
        $this->assertTrue($document->assets()[0]->resolved());
        $this->assertFalse($document->assets()[1]->resolved());
        $this->assertContains('link.unresolved', $this->diagnosticCodes($document));
        $this->assertContains('asset.unresolved', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем блокировку опасных URL schemes после render-этапа.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testSanitizesMaliciousLinks(): void
    {
        $document = new MarkdownRenderer()->render(
            '<a href="javascript:alert(1)">bad</a><img src="data:text/html;base64,xxx">',
            new MarkdownRenderOptions(htmlPolicy: MarkdownHtmlPolicy::Allow),
        );

        $this->assertStringNotContainsString('javascript:alert', $document->html());
        $this->assertStringNotContainsString('data:text/html', $document->html());
        $this->assertContains('link.unresolved', $this->diagnosticCodes($document));
        $this->assertContains('asset.unresolved', $this->diagnosticCodes($document));
    }

    /**
     * Проверяем target/rel policy для внешних ссылок.
     *
     * @see MarkdownRenderer::render()
     */
    #[Test]
    public function testAppliesExternalLinkPolicy(): void
    {
        $document = new MarkdownRenderer()->render(
            '[external](https://example.com)',
            new MarkdownRenderOptions(externalLinkTarget: '_blank', externalLinksNoFollow: true),
        );

        $html = $document->html();

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="nofollow noopener noreferrer"', $html);
    }

    /**
     * @return list<string>
     */
    private function diagnosticCodes(MarkdownDocument $document): array
    {
        return array_map(static fn (MarkdownDiagnostic $diagnostic): string => $diagnostic->code(), $document->diagnostics());
    }
}
