<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Forms\Components;

use Filament\Forms\Components\Hidden;
use Illuminate\Support\Str;

/**
 * A stable id for repeater and builder items, so their translations
 * follow them when items are added, removed or reordered. Add it to the
 * item schema of the content form and of the translation form:
 *
 *     Repeater::make('features')->schema([ItemId::make(), TextInput::make('title')])
 *
 * New items get a ULID; items saved before get one when the form loads.
 */
class ItemId extends Hidden
{
    public static function make(?string $name = 'id'): static
    {
        return parent::make($name ?? 'id');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->default(fn (): string => (string) Str::ulid())
            ->afterStateHydrated(function (ItemId $component, mixed $state): void {
                if (blank($state)) {
                    $component->state((string) Str::ulid());
                }
            });
    }
}
