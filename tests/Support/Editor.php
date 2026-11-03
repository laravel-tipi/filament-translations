<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Support;

use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert;
use Tipi\Translations\Filament\Content\Segment;

/**
 * Drives a translation editor the same way whether it is a page or a modal.
 */
final class Editor
{
    public function __construct(
        public Testable $livewire,
        public string $path,
        public bool $page,
    ) {}

    public function state(?string $key = null): mixed
    {
        return $this->livewire->get($key === null ? $this->path : "{$this->path}.{$key}");
    }

    public function set(string $key, mixed $value): self
    {
        $this->livewire->set("{$this->path}.{$key}", $value);

        return $this;
    }

    public function switchTo(string $locale): self
    {
        return $this->set('locale', $locale);
    }

    public function reference(string $locale): self
    {
        return $this->set('reference', $locale);
    }

    /**
     * Type translations, by segment id: "name", "description/0~0",
     * "notes/a/note".
     *
     * @param  array<string, string|null>  $texts
     */
    public function fill(string $locale, array $texts): self
    {
        foreach ($texts as $id => $text) {
            $this->set(self::path($locale, (string) $id), $text);
        }

        return $this;
    }

    public function text(string $locale, string $id): mixed
    {
        return $this->state(self::path($locale, $id));
    }

    public static function path(string $locale, string $id): string
    {
        return "translations.{$locale}.".Segment::keyOf($id);
    }

    public function conflicts(string $locale): array
    {
        return $this->state("conflicts.{$locale}") ?? [];
    }

    public function save(): self
    {
        $this->page ? $this->livewire->call('save') : $this->livewire->callMountedAction();

        return $this;
    }

    public function assertSaved(): self
    {
        $this->livewire->assertHasNoErrors();

        if (! $this->page) {
            $this->livewire->assertHasNoActionErrors();
        }

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $segments  locale => segment ids
     */
    public function assertErrors(array $segments): self
    {
        $keys = [];

        foreach ($segments as $locale => $ids) {
            foreach ($ids as $id) {
                $keys[] = "{$this->path}.".self::path($locale, $id);
            }
        }

        $this->livewire->assertHasErrors($keys);

        return $this;
    }

    public function assertStillOpen(): self
    {
        if (! $this->page) {
            Assert::assertNotEmpty($this->livewire->get('mountedActions'), 'The editor modal closed.');
        }

        return $this;
    }

    public function html(): string
    {
        return $this->livewire->html();
    }
}
