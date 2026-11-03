<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Support;

use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert;

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

    public function source(string $locale): self
    {
        return $this->set('source_locale', $locale);
    }

    public function fill(string $locale, array $values): self
    {
        foreach ($values as $attribute => $value) {
            $this->set("translations.{$locale}.{$attribute}", $value);
        }

        return $this;
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

    public function assertErrors(array $keys): self
    {
        $this->livewire->assertHasErrors(array_map(fn (string $key): string => "{$this->path}.{$key}", $keys));

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
