<?php

declare(strict_types=1);

namespace PhpSoftBox\Markdown;

use PhpSoftBox\Markdown\Contracts\FrontMatterParserInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function is_array;
use function preg_match;

final class YamlFrontMatterParser implements FrontMatterParserInterface
{
    public function parse(string $source): MarkdownSource
    {
        if (preg_match('~\A---\R(.*?)\R---\R?(.*)\z~s', $source, $matches) !== 1) {
            return new MarkdownSource([], $source);
        }

        $diagnostics = [];
        $frontMatter = [];

        try {
            $parsed      = Yaml::parse($matches[1]);
            $frontMatter = is_array($parsed) ? $parsed : [];
        } catch (ParseException $exception) {
            $diagnostics[] = new MarkdownDiagnostic(
                MarkdownDiagnosticLevel::Error,
                'front_matter.invalid',
                $exception->getMessage(),
                $exception->getParsedLine(),
            );
        }

        return new MarkdownSource($frontMatter, $matches[2], $diagnostics);
    }
}
