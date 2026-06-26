<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

enum MarkdownDiagnosticLevel: string
{
    case Info    = 'info';
    case Warning = 'warning';
    case Error   = 'error';
}
