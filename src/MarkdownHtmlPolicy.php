<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

enum MarkdownHtmlPolicy: string
{
    case Escape = 'escape';
    case Strip  = 'strip';
    case Allow  = 'allow';
}
