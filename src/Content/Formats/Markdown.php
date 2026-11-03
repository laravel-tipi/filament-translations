<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Format;
use Tipi\Translations\Filament\Content\Inline;

/**
 * Markdown, as CommonMark with GitHub's tables, strikethrough and task
 * lists (what Filament's MarkdownEditor writes).
 *
 * Read with league/commonmark into blocks and runs. A translation is
 * written from that tree, not by replacing text in the source: block
 * structure, list markers, code and HTML are the source's, inline
 * formatting is written canonically (*italic*, **bold**, ~~struck~~,
 * [links](url)) and text is escaped, so translated text can never become
 * Markdown syntax.
 *
 * @internal
 */
final class Markdown implements Format
{
    /**
     * Stands in for the text of an untranslated block that can't be empty
     * (a paragraph), so the document keeps the source's structure.
     */
    public const string EMPTY = '&#8203;';

    private ?MarkdownParser $parser = null;

    public function read(mixed $value): ?Document
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $reader = new MarkdownReader;
        $blocks = $reader->blocks($this->parser()->parse($value)->children(), '');

        if ($blocks === []) {
            return null;
        }

        return new MarkdownDocument($blocks, $reader->runs, $reader->specs);
    }

    private function parser(): MarkdownParser
    {
        if ($this->parser === null) {
            $environment = new Environment;
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new TableExtension);
            $environment->addExtension(new StrikethroughExtension);
            $environment->addExtension(new TaskListExtension);

            $this->parser = new MarkdownParser($environment);
        }

        return $this->parser;
    }
}
