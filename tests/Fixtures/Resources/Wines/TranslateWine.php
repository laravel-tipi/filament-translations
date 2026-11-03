<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines;

use Tipi\Translations\Filament\Resources\Pages\TranslateRecord;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;

final class TranslateWine extends TranslateRecord
{
    protected static string $resource = WineResource::class;
}
