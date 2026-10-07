<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components;

use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Tipi\Translations\Filament\Schemas\Components\Concerns\HasTranslation;
use Tipi\Translations\Filament\Schemas\Components\Contracts\TranslationField;

class TranslationTextarea extends Textarea implements TranslationField
{
    use HasTranslation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTranslation();
    }

    public function getSourceEntry(string $name): Component
    {
        return TextEntry::make($name);
    }
}
