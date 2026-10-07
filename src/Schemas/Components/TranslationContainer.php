<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class TranslationContainer extends Component
{
    protected string $view = 'tipi-filament-translations::schemas.components.translation-container';

    /**
     * @var array<string>
     */
    protected array $contentRows = [];

    protected bool|Closure $sourceVisible = true;

    public static function make(): static
    {
        $static = app(static::class);
        $static->configure();

        return $static;
    }

    public function sourceVisible(bool|Closure $condition = true): static
    {
        $this->sourceVisible = $condition;

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $schema
     */
    public function sourceLocale(array|Closure $schema): static
    {
        $this->childComponents($schema, 'source_locale');

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $schema
     */
    public function targetLocale(array|Closure $schema): static
    {
        $this->childComponents($schema, 'target_locale');

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $schema
     */
    public function sourceHeader(array|Closure $schema): static
    {
        $this->childComponents($schema, 'source_header');

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $schema
     */
    public function targetHeader(array|Closure $schema): static
    {
        $this->childComponents($schema, 'target_header');

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $source
     * @param  array<Component> | Closure  $target
     */
    public function content(
        string $name,
        array|Closure $source,
        array|Closure $target,
    ): static {
        $sourceKey = "content.$name.source";
        $targetKey = "content.$name.target";
        $this->childComponents($source, $sourceKey);
        $this->childComponents($target, $targetKey);
        $this->contentRows[] = $name;

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $schema
     */
    public function sourceFooter(array|Closure $schema): static
    {
        $this->childComponents($schema, 'source_footer');

        return $this;
    }

    /**
     * @param  array<Component> | Closure  $schema
     */
    public function targetFooter(array|Closure $schema): static
    {
        $this->childComponents($schema, 'target_footer');

        return $this;
    }

    public function isSourceVisible(): bool
    {
        return (bool) $this->evaluate($this->sourceVisible);
    }

    public function getSourceLocaleSchema(): ?Schema
    {
        return $this->getChildSchema('source_locale');
    }

    public function getTargetLocaleSchema(): ?Schema
    {
        return $this->getChildSchema('target_locale');
    }

    public function getSourceHeaderSchema(): ?Schema
    {
        return $this->getChildSchema('source_header');
    }

    public function getTargetHeaderSchema(): ?Schema
    {
        return $this->getChildSchema('target_header');
    }

    /**
     * @return array<string>
     */
    public function getContentRows(): array
    {
        return $this->contentRows;
    }

    public function getSourceContentSchema(string $name): ?Schema
    {
        return $this->getChildSchema("content.{$name}.source");
    }

    public function getTargetContentSchema(string $name): ?Schema
    {
        return $this->getChildSchema("content.{$name}.target");
    }

    public function getSourceFooterSchema(): ?Schema
    {
        return $this->getChildSchema('source_footer');
    }

    public function getTargetFooterSchema(): ?Schema
    {
        return $this->getChildSchema('target_footer');
    }

    /**
     * @param  array<Schema|null>  $schemas
     */
    protected function validateSchemas(array $schemas): void
    {
        $rules = [];
        $messages = [];
        $attributes = [];

        foreach ($schemas as $schema) {
            if ($schema === null) {
                continue;
            }

            $rules = [
                ...$rules,
                ...$schema->getValidationRules(),
            ];

            $messages = [
                ...$messages,
                ...$schema->getValidationMessages(),
            ];

            $attributes = [
                ...$attributes,
                ...$schema->getValidationAttributes(),
            ];
        }

        if ($rules === []) {
            return;
        }

        $this->getLivewire()->validate(
            $rules,
            $messages,
            $attributes,
        );
    }

    public function validateTarget(): void
    {
        $this->validateSchemas([
            $this->getTargetLocaleSchema(),
            ...array_map(
                fn (string $row) => $this->getTargetContentSchema($row),
                $this->getContentRows(),
            ),
        ]);
    }

    public function validateSource(): void
    {
        $this->validateSchemas([
            $this->getSourceLocaleSchema(),
            ...array_map(
                fn (string $row) => $this->getSourceContentSchema($row),
                $this->getContentRows(),
            ),
        ]);
    }
}
