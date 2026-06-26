<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

final readonly class MarkdownDiagnostic
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private MarkdownDiagnosticLevel $level,
        private string $code,
        private string $message,
        private ?int $line = null,
        private ?int $column = null,
        private array $context = [],
    ) {
    }

    public function level(): MarkdownDiagnosticLevel
    {
        return $this->level;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function column(): ?int
    {
        return $this->column;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
