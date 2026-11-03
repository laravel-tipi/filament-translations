<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class WineTranslation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['notes' => 'array', 'content' => 'array'];
    }
}
