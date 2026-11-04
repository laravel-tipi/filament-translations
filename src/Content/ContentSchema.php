<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use Tipi\Translations\Filament\Content\Formats\Markdown;
use Tipi\Translations\Filament\Content\Formats\RichText;
use Tipi\Translations\Filament\Content\Nodes\DocumentNode;
use Tipi\Translations\Filament\Content\Nodes\ItemsNode;
use Tipi\Translations\Filament\Content\Nodes\Node;
use Tipi\Translations\Filament\Content\Nodes\ObjectNode;
use Tipi\Translations\Filament\Content\Nodes\TextNode;

/**
 * The translatable shape of a record's attributes, read from the
 * translation form. The form declares content with the fields that
 * author it; the editor never renders them:
 *
 *     TextInput, Textarea   plain text
 *     MarkdownEditor        Markdown
 *     RichEditor            HTML, or TipTap JSON
 *     Repeater, Builder     items; their text fields are translated, any
 *                           other data is the source's
 *
 * Layout components group fields; components with a state path nest them.
 *
 * @internal
 */
final readonly class ContentSchema
{
    /**
     * @param  array<string, Node>  $nodes
     * @param  array<string, string>  $labels
     */
    private function __construct(
        private array $nodes,
        private array $labels,
    ) {}

    /**
     * @param  array<mixed>  $components
     * @param  callable(string): bool  $isTranslatable
     * @param  array<string, list<string>>  $customBlocks  rich editor custom block id => translatable config keys
     */
    public static function fromComponents(array $components, callable $isTranslatable, ?string $idKey = 'id', array $customBlocks = []): self
    {

        $fields = [];
        $labels = [];

        self::collect($components, '', $fields, $labels);

        $nodes = [];

        foreach ($fields as $path => $field) {
            $attribute = Str::before($path, '.');

            if (! $isTranslatable($attribute)) {
                throw new LogicException("The translation form field [{$path}] is not a translatable attribute.");
            }

            $node = self::nodeFor($field, $idKey, $customBlocks, nested: false);

            if ($node === null) {
                throw new LogicException(sprintf(
                    'The translation form field [%s] (%s) can\'t be translated as text. Use TextInput, Textarea, MarkdownEditor, RichEditor, Repeater or Builder fields.',
                    $path,
                    class_basename($field),
                ));
            }

            $nodes[$path] = $node;
        }

        if ($nodes === []) {
            throw new LogicException('The translation form has no fields.');
        }

        // Fields of one attribute at nested paths ("seo.title") form an object.
        $attributes = [];

        foreach ($nodes as $path => $node) {
            $attribute = Str::before($path, '.');
            $attributes[$attribute][$path === $attribute ? '' : Str::after($path, '.')] = $node;
        }

        $schema = [];

        foreach ($attributes as $attribute => $parts) {
            $schema[$attribute] = isset($parts['']) && count($parts) === 1 ? $parts[''] : self::object($parts, $labels, $attribute);
        }

        return new self($schema, array_intersect_key($labels, $schema));
    }

    /**
     * @return list<string>
     */
    public function attributes(): array
    {
        return array_keys($this->nodes);
    }

    public function node(string $attribute): Node
    {
        return $this->nodes[$attribute];
    }

    public function label(string $attribute): string
    {
        return $this->labels[$attribute] ?? Str::headline($attribute);
    }

    /**
     * The segments of a value of the attribute.
     *
     * @return list<Segment>
     *
     * @throws UnsupportedContent
     */
    public function segments(string $attribute, mixed $value): array
    {
        return $this->nodes[$attribute]->segments($attribute, '', [], $value);
    }

    public function align(string $attribute, mixed $source, mixed $target): Alignment
    {
        $alignment = new Alignment;

        try {
            $this->nodes[$attribute]->align('', $source, $target, $alignment);
        } catch (UnsupportedContent) {
            // The stored translation can't be read: nothing lines up.
        }

        return $alignment;
    }

    public function skeleton(string $attribute, mixed $value): string
    {
        return $this->nodes[$attribute]->skeleton($value);
    }

    /**
     * @param  array<mixed>  $components
     * @param  array<string, Field>  $fields
     * @param  array<string, string>  $labels
     */
    private static function collect(array $components, string $prefix, array &$fields, array &$labels): void
    {
        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if ($component instanceof Field) {
                $path = ltrim($prefix.'.'.$component->getName(), '.');
                $fields[$path] = $component;
                $labels[$path] = self::fieldLabel($component, $path);

                continue;
            }

            $statePath = $component->getStatePath(isAbsolute: false);
            $children = $component->getDefaultChildComponents();

            if (is_array($children)) {
                self::collect($children, filled($statePath) ? ltrim($prefix.'.'.$statePath, '.') : $prefix, $fields, $labels);
            }
        }
    }

    /**
     * Inside items, inputs for numbers, emails, URLs and such are data,
     * not text: they are kept from the source.
     *
     * @param  array<string, list<string>>  $blocks  rich editor custom blocks
     */
    private static function nodeFor(Field $field, ?string $idKey, array $blocks, bool $nested = true): ?Node
    {
        return match (true) {
            $field instanceof TextInput => ! $nested || in_array($field->getType(), ['text', 'search'], true) ? new TextNode(false, self::length($field)) : null,
            $field instanceof Textarea => new TextNode(true, self::length($field)),
            $field instanceof MarkdownEditor => new DocumentNode(new Markdown),
            $field instanceof RichEditor => new DocumentNode(new RichText(fn () => $field->getTipTapEditor(), $blocks)),
            $field instanceof Repeater => self::repeater($field, $idKey, $blocks),
            $field instanceof Builder => self::builder($field, $idKey, $blocks),
            default => null,
        };
    }

    /**
     * @param  array<string, list<string>>  $blocks
     */
    private static function repeater(Repeater $repeater, ?string $idKey, array $blocks): Node
    {
        $fixed = self::isFixed($repeater);
        $simple = $repeater->getSimpleField();

        if ($simple !== null) {
            // Simple items are plain values: they can't carry an id.
            if (! $fixed) {
                throw self::identityError($repeater, 'a simple repeater can\'t give its items an id: use ->schema([ItemId::make(), …]) instead of ->simple(), or make it fixed with ->addable(false)->deletable(false)->reorderable(false)');
            }

            return new ItemsNode(self::nodeFor($simple, $idKey, $blocks) ?? new ObjectNode([]), idKey: null, positional: true);
        }

        $children = $repeater->getDefaultChildComponents();
        $children = is_array($children) ? $children : [];

        if (! $fixed) {
            self::requireIdField($repeater, $children, $idKey);
        }

        return new ItemsNode(self::itemNode($children, $idKey, $blocks), idKey: $idKey, positional: $fixed);
    }

    /**
     * @param  array<string, list<string>>  $customBlocks
     */
    private static function builder(Builder $builder, ?string $idKey, array $customBlocks): Node
    {
        $fixed = self::isFixed($builder);
        $blocks = [];
        $labels = [];

        // The declared blocks: getBlocks() needs a rendered form.
        $declared = $builder->getDefaultChildComponents();

        foreach (is_array($declared) ? $declared : [] as $block) {
            if (! $block instanceof Block) {
                continue;
            }

            $name = $block->getName();
            $children = $block->getDefaultChildComponents();
            $children = is_array($children) ? $children : [];

            if (! $fixed) {
                self::requireIdField($builder, $children, $idKey, $name);
            }

            $blocks[$name] = self::itemNode($children, $idKey, $customBlocks);

            try {
                $labels[$name] = strip_tags((string) $block->getLabel());
            } catch (Throwable) {
                $labels[$name] = Str::headline($name);
            }
        }

        return new ItemsNode(blocks: $blocks, blockLabels: $labels, idKey: $idKey, positional: $fixed);
    }

    /**
     * Whether items can't be added, deleted or reordered: then positions
     * identify them. Read from the field's settings; a setting given as a
     * closure counts as "can".
     */
    private static function isFixed(Repeater|Builder $field): bool
    {
        $settings = (fn (): array => [$this->isAddable, $this->isDeletable, $this->isReorderable])->call($field);

        return ! in_array(true, array_map(fn (mixed $setting): bool => $setting !== false, $settings), true);
    }

    /**
     * @param  array<mixed>  $children
     */
    private static function requireIdField(Repeater|Builder $field, array $children, ?string $idKey, ?string $block = null): void
    {
        $fields = [];
        $labels = [];
        self::collect($children, '', $fields, $labels);

        if ($idKey !== null && isset($fields[$idKey])) {
            return;
        }

        throw self::identityError($field, sprintf(
            'its items%s need a stable id field [%s] to keep translations with their items when items are added, deleted or reordered: add ItemId::make(%s) to the item schema of the content form and the translation form, or make the %s fixed with ->addable(false)->deletable(false)->reorderable(false)',
            $block === null ? '' : " (block [{$block}])",
            (string) $idKey,
            $idKey === null || $idKey === 'id' ? '' : "'{$idKey}'",
            $field instanceof Builder ? 'builder' : 'repeater',
        ));
    }

    private static function identityError(Repeater|Builder $field, string $reason): LogicException
    {
        return new LogicException(sprintf('The translation form field [%s] can\'t be translated safely: %s.', $field->getName(), $reason));
    }

    /**
     * An item's text fields; its other fields are kept from the source.
     *
     * @param  array<mixed>  $components
     * @param  array<string, list<string>>  $blocks
     */
    private static function itemNode(array $components, ?string $idKey, array $blocks): ObjectNode
    {
        $fields = [];
        $labels = [];
        self::collect($components, '', $fields, $labels);

        $nodes = [];

        foreach ($fields as $path => $field) {
            $node = self::nodeFor($field, $idKey, $blocks);

            if ($node !== null) {
                $nodes[$path] = $node;
            }
        }

        return self::object($nodes, $labels, '');
    }

    /**
     * Nodes at dotted paths as nested objects.
     *
     * @param  array<string, Node>  $nodes
     * @param  array<string, string>  $labels
     */
    private static function object(array $nodes, array $labels, string $prefix): ObjectNode
    {
        $fields = [];
        $nested = [];

        foreach ($nodes as $path => $node) {
            if (str_contains($path, '.')) {
                $nested[Str::before($path, '.')][Str::after($path, '.')] = $node;
            } else {
                $fields[$path] = $node;
            }
        }

        foreach ($nested as $key => $children) {
            $fields[$key] = self::object($children, $labels, ltrim($prefix.'.'.$key, '.'));
        }

        $fieldLabels = [];

        foreach (array_keys($fields) as $key) {
            $fieldLabels[$key] = $labels[ltrim($prefix.'.'.$key, '.')] ?? Str::headline($key);
        }

        return new ObjectNode($fields, $fieldLabels);
    }

    /**
     * @return array{min?: int, max?: int}
     */
    private static function length(TextInput|Textarea $field): array
    {
        try {
            $length = $field->getLength();

            return array_filter([
                'min' => $length ?? $field->getMinLength(),
                'max' => $length ?? $field->getMaxLength(),
            ], fn (?int $value): bool => $value !== null);
        } catch (Throwable) {
            return [];
        }
    }

    private static function fieldLabel(Field $field, string $path): string
    {
        try {
            $label = $field->getLabel();
        } catch (Throwable) {
            $label = null;
        }

        return filled($label) ? strip_tags((string) $label) : Str::headline(Str::afterLast($path, '.'));
    }
}
