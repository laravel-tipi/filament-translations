<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components;

use Filament\Forms\Components\Textarea;
use Tipi\Translations\Filament\Schemas\Components\Concerns\HasTranslationState;

class TranslationTextarea extends Textarea
{
    use HasTranslationState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTranslation();
    }
}
