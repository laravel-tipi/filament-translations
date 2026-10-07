<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components;

use Filament\Forms\Components\RichEditor;
use Tipi\Translations\Filament\Schemas\Components\Concerns\HasTranslationState;

class TranslationRichEditor extends RichEditor
{
    use HasTranslationState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTranslation();
    }
}
