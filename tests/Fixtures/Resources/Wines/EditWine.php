<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines;

use Filament\Resources\Pages\EditRecord;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;

final class EditWine extends EditRecord
{
    protected static string $resource = WineResource::class;

    protected function getHeaderActions(): array
    {
        return [TranslateAction::make()];
    }
}
