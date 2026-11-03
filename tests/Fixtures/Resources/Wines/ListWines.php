<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines;

use Filament\Resources\Pages\ListRecords;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;

final class ListWines extends ListRecords
{
    protected static string $resource = WineResource::class;
}
