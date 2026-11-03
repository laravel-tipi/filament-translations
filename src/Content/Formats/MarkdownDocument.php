<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Formats;

use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Run;

/**
 * @internal
 */
final readonly class MarkdownDocument implements Document
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, Run>  $runs
     * @param  array<string, array<string, array<string, mixed>>>  $specs
     */
    public function __construct(
        private array $blocks,
        private array $runs,
        private array $specs,
    ) {}

    public function runs(): array
    {
        return $this->runs;
    }

    public function skeleton(): string
    {
        return md5(json_encode(self::skeletonOf($this->blocks), JSON_THROW_ON_ERROR));
    }

    public function rebuild(array $trees): mixed
    {
        $writer = new MarkdownWriter($this->runs, $this->specs, $trees);

        return $writer->blocks($this->blocks)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<mixed>
     */
    private static function skeletonOf(array $blocks): array
    {
        return array_map(fn (array $block): array => match ($block['type']) {
            'paragraph' => ['p', $block['task']],
            'heading' => ['h', $block['level']],
            'quote' => ['q', self::skeletonOf($block['children'])],
            'list' => ['l', $block['ordered'], $block['start'], $block['delimiter'], $block['bullet'], $block['tight'], array_map(self::skeletonOf(...), $block['items'])],
            'code' => ['c', md5($block['info']."\n".$block['literal'])],
            'html' => ['x', md5($block['literal'])],
            'hr' => ['hr'],
            'table' => ['t', $block['align'], count($block['head']), array_map(count(...), $block['rows'])],
        }, $blocks);
    }
}
