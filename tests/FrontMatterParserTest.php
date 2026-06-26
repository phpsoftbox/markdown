<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown\Tests;

use PhpSoftBox\Markdown\YamlFrontMatterParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(YamlFrontMatterParser::class)]
final class FrontMatterParserTest extends TestCase
{
    /**
     * Проверяем разбор YAML front matter и отделение тела документа.
     *
     * @see YamlFrontMatterParser::parse()
     */
    #[Test]
    public function testParsesValidFrontMatter(): void
    {
        $source = <<<MD
---
title: Установка
draft: false
sidebar_position: 10
---
# Body
MD;

        $parsed = new YamlFrontMatterParser()->parse($source);

        $this->assertSame('Установка', $parsed->frontMatter()['title']);
        $this->assertFalse($parsed->frontMatter()['draft']);
        $this->assertSame(10, $parsed->frontMatter()['sidebar_position']);
        $this->assertSame('# Body', $parsed->body());
        $this->assertSame([], $parsed->diagnostics());
    }

    /**
     * Проверяем, что невалидный YAML возвращает diagnostic и не ломает тело документа.
     *
     * @see YamlFrontMatterParser::parse()
     */
    #[Test]
    public function testReturnsDiagnosticForInvalidFrontMatter(): void
    {
        $source = <<<MD
---
title: [
---
# Body
MD;

        $parsed = new YamlFrontMatterParser()->parse($source);

        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code(), $parsed->diagnostics());

        $this->assertSame([], $parsed->frontMatter());
        $this->assertSame('# Body', $parsed->body());
        $this->assertContains('front_matter.invalid', $codes);
    }
}
