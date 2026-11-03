<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions;

use Tipi\Translations\Filament\Resources\Pages\TranslateRecord;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\RegionResource;

final class TranslateRegion extends TranslateRecord
{
    protected static string $resource = RegionResource::class;
}
