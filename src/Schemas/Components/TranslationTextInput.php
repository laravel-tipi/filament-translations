<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;
use Tipi\Translations\Filament\Schemas\Components\Concerns\HasTranslationState;

class TranslationTextInput extends TextInput
{
    use HasTranslationState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTranslation();
    }
}
