# PhpSoftBox Markdown

Компонент безопасного рендера Markdown-документов.

Пакет дает framework-level API поверх Markdown parser и YAML parser. Он умеет
разбирать Markdown source, front matter, строить HTML, TOC, heading anchors,
обрабатывать расширения синтаксиса и возвращать diagnostics без привязки к тому,
где именно используется результат: в документации, админке, CMS, письмах или другом
app-layer.

```php
use PhpSoftBox\Markdown\MarkdownRenderer;

$document = new MarkdownRenderer()->render(<<<'MD'
---
title: Установка
---

## Быстрый старт

Текст документации.
MD);

echo $document->html();
```

## Возможности

- CommonMark/GFM Markdown;
- YAML front matter;
- deterministic `id` у заголовков;
- `MarkdownToc` по заданным уровням заголовков;
- fenced code blocks с `language` и `title="file.php"`;
- admonitions `note`, `tip`, `info`, `warning`, `danger`;
- tabs-блоки;
- diagnostics без падения рендера;
- resolver ссылок и assets;
- raw HTML disabled by default;
- блокировка опасных URL schemes.

## Front Matter

```markdown
---
title: Установка
slug: /quick-start/installation
sidebar_position: 10
draft: false
---

# Установка
```

```php
$document = $renderer->render($source);

$document->frontMatter(); // ['title' => 'Установка', ...]
```

Если YAML невалидный, компонент вернет diagnostic `front_matter.invalid`, но тело
документа останется доступным.

## TOC И Anchors

Заголовки получают стабильные `id`:

```markdown
## Install
## Install
```

Результат:

```html
<h2 id="install">Install</h2>
<h2 id="install-2">Install</h2>
```

Диапазон TOC настраивается:

```php
use PhpSoftBox\Markdown\MarkdownRenderOptions;

$document = $renderer->render($source, new MarkdownRenderOptions(
    tocMinHeadingLevel: 2,
    tocMaxHeadingLevel: 3,
));
```

## Links И Assets

Renderer не знает файловую структуру, правила роутинга и публичные URL приложения.
Для этого передается resolver:

```php
use PhpSoftBox\Markdown\Contracts\MarkdownLinkResolverInterface;
use PhpSoftBox\Markdown\MarkdownRenderContext;
use PhpSoftBox\Markdown\MarkdownResolvedAsset;
use PhpSoftBox\Markdown\MarkdownResolvedLink;

final class AppMarkdownLinkResolver implements MarkdownLinkResolverInterface
{
    public function resolveLink(string $target, MarkdownRenderContext $context): MarkdownResolvedLink
    {
        return MarkdownResolvedLink::resolved('/content/' . trim($target, './'));
    }

    public function resolveAsset(string $target, MarkdownRenderContext $context): MarkdownResolvedAsset
    {
        return MarkdownResolvedAsset::resolved('/assets/content/' . trim($target, './'));
    }
}
```

```php
$document = $renderer->render($source, new MarkdownRenderOptions(
    basePath: '/content',
    currentDocumentPath: 'articles/install.md',
    linkResolver: new AppMarkdownLinkResolver(),
));
```

Если resolver не смог найти цель, он возвращает `MarkdownResolvedLink::unresolved()`
или `MarkdownResolvedAsset::unresolved()`. Renderer добавит diagnostics
`link.unresolved` или `asset.unresolved`.

## Code Blocks

Поддерживается `title` в info string:

````markdown
```php title="src/Action.php"
echo 'ok';
```
````

В HTML появятся `data-language`, `data-title` и подпись блока.

Если задан `allowedCodeLanguages`, неизвестный язык попадет в diagnostic
`code.language_unknown`.

## Admonitions

```markdown
:::warning
Перед деплоем проверьте переменные окружения.
:::
```

Поддержанные типы:

- `note`;
- `tip`;
- `info`;
- `warning`;
- `danger`.

Неизвестный тип возвращает diagnostic `admonition.unknown`.

## Tabs

Tabs-блок задается контейнером `:::tabs` и секциями `@tab`:

````markdown
:::tabs
@tab PHP
```php
echo 'ok';
```

@tab JavaScript
```js
console.log('ok');
```
:::
````

Renderer вернет статичный HTML:

```html
<div class="markdown-tabs">
    <div class="markdown-tabs__nav" role="tablist">...</div>
    <div class="markdown-tabs__panels">...</div>
</div>
```

Компонент не навязывает JS-поведение. Приложение может подключить собственный
скрипт/React-компонент поверх классов `markdown-tabs*` и `data-tab`.

Некорректный блок возвращает diagnostic `tabs.invalid`.

## HTML Safety

По умолчанию raw HTML экранируется:

```php
use PhpSoftBox\Markdown\MarkdownHtmlPolicy;
use PhpSoftBox\Markdown\MarkdownRenderOptions;

$document = $renderer->render($source, new MarkdownRenderOptions(
    htmlPolicy: MarkdownHtmlPolicy::Escape,
));
```

Доступные политики:

- `MarkdownHtmlPolicy::Escape`;
- `MarkdownHtmlPolicy::Strip`;
- `MarkdownHtmlPolicy::Allow`.

Даже при разрешенном HTML renderer блокирует опасные URL schemes:
`javascript:`, `vbscript:`, `data:`.

`MarkdownHtmlPolicy::Allow` — только для доверенного контента: renderer не санитизирует
HTML, `<script>`, `<iframe>` и атрибуты-обработчики (`onerror`, `onclick`) попадают в
результат как есть. Для пользовательского ввода используйте `Escape` или `Strip`.

Для внешних ссылок можно включить target/rel:

```php
$document = $renderer->render($source, new MarkdownRenderOptions(
    externalLinkTarget: '_blank',
    externalLinksNoFollow: true,
));
```

## Diagnostics

Минимальные коды:

- `front_matter.invalid`;
- `link.unresolved`;
- `asset.unresolved`;
- `html.disallowed`;
- `admonition.unknown`;
- `tabs.invalid`;
- `heading.duplicate_id`;
- `code.language_unknown`.

```php
foreach ($document->diagnostics() as $diagnostic) {
    echo $diagnostic->code() . ': ' . $diagnostic->message();
}
```

## Лицензия

MIT
