<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content\Nodes;

use Tipi\Translations\Filament\Content\Alignment;
use Tipi\Translations\Filament\Content\Document;
use Tipi\Translations\Filament\Content\Format;
use Tipi\Translations\Filament\Content\Inline;
use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Run;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Content\Tag;

/**
 * Rich text (Markdown, HTML): a segment per run with text.
 *
 * Rebuilding is checked: the result is read again and must have the
 * source's structure and exactly the translated texts. When the format
 * can't express a translation, that segment is invalid; content is never
 * stored differently from what the translator wrote.
 *
 * @internal
 */
final readonly class DocumentNode implements Node
{
    public function __construct(public Format $format) {}

    public function segments(string $attribute, string $path, array $context, mixed $value): array
    {
        $document = $this->format->read($value);

        if ($document === null) {
            return [];
        }

        $segments = [];

        foreach ($document->runs() as $run) {
            if ($run->hasText()) {
                $segments[] = new Segment(
                    $attribute,
                    Alignment::join($path, $run->path),
                    $context,
                    $run->role,
                    $run->text(),
                    $run->tags,
                    $run->multiline,
                    rich: $run->role !== 'alt',
                );
            }
        }

        return $segments;
    }

    public function skeleton(mixed $value): string
    {
        return $this->format->read($value)?->skeleton() ?? 'empty';
    }

    public function align(string $path, mixed $source, mixed $target, Alignment $alignment): void
    {
        $target = $this->format->read($target);

        if ($target === null) {
            return;
        }

        $source = $this->format->read($source);

        if ($source === null || $source->skeleton() !== $target->skeleton()) {
            foreach ($target->runs() as $run) {
                if ($run->hasText()) {
                    $alignment->orphans[] = $run->text();
                }
            }

            return;
        }

        $runs = $target->runs();

        foreach ($source->runs() as $runPath => $sourceRun) {
            $run = $runs[$runPath] ?? null;

            if ($run === null || ! $run->hasText() || ! $sourceRun->hasText()) {
                continue;
            }

            $tree = $run->namedLike($sourceRun);

            if ($tree === null) {
                $alignment->orphans[] = $run->text();
            } else {
                $alignment->texts[Alignment::join($path, (string) $runPath)] = Inline::render($tree);
            }
        }
    }

    public function rebuild(string $path, mixed $source, array $texts): mixed
    {
        $document = $this->format->read($source);

        if ($document === null) {
            return null;
        }

        $trees = [];

        foreach ($document->runs() as $runPath => $run) {
            if (! $run->hasText()) {
                continue;
            }

            $segmentPath = Alignment::join($path, (string) $runPath);
            $tree = self::tree($run, $texts[$segmentPath] ?? null, $segmentPath);

            if ($tree !== null) {
                $trees[$runPath] = $tree;
            }
        }

        if ($trees === []) {
            return null;
        }

        $value = $document->rebuild($trees);

        $this->verify($document, $value, $trees, $path);

        return $value;
    }

    /**
     * A translated text as a tree with the run's tags, or null when blank.
     *
     * @return list<string|Tag>|null
     *
     * @throws InvalidTranslation
     */
    public static function tree(Run $run, ?string $text, string $path): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if ($run->role === 'alt') {
            return [trim((string) preg_replace('/\s+/u', ' ', $text))];
        }

        try {
            $tree = Inline::parse($text, $run->tagForms());
        } catch (InvalidTranslation $exception) {
            throw $exception->at($path);
        }

        if (! $run->multiline && in_array(true, array_map(fn (string|Tag $node): bool => self::hasBreak($node), $tree), true)) {
            throw (new InvalidTranslation('single_line'))->at($path);
        }

        return Inline::hasText($tree) ? $tree : null;
    }

    /**
     * Read the rebuilt value again: it must have the source's structure and
     * the translated texts, and nothing else.
     *
     * @param  array<string, list<string|Tag>>  $trees
     *
     * @throws InvalidTranslation
     */
    private function verify(Document $source, mixed $value, array $trees, string $path): void
    {
        $rebuilt = $this->format->read($value);

        if ($rebuilt === null || $rebuilt->skeleton() !== $source->skeleton()) {
            throw (new InvalidTranslation('format'))->at(Alignment::join($path, (string) array_key_first($trees)));
        }

        $runs = $rebuilt->runs();

        foreach ($source->runs() as $runPath => $sourceRun) {
            if (! $sourceRun->hasText()) {
                continue;
            }

            $expected = isset($trees[$runPath]) ? Inline::render(Inline::canonical($trees[$runPath])) : '';
            $run = $runs[$runPath] ?? null;
            $actual = $run === null || ! $run->hasText() ? '' : (($tree = $run->namedLike($sourceRun)) === null ? null : Inline::render($tree));

            if ($actual !== $expected) {
                throw (new InvalidTranslation('format'))->at(Alignment::join($path, (string) $runPath));
            }
        }
    }

    private static function hasBreak(string|Tag $node): bool
    {
        if (is_string($node)) {
            return str_contains($node, "\n");
        }

        return in_array(true, array_map(self::hasBreak(...), $node->children), true);
    }
}
