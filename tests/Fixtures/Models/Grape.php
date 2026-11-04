<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * JSON storage, without translation states.
 */
#[Translatable(['name', 'description', 'notes'])]
class Grape extends Model implements TranslatableModel
{
    use HasJsonTranslations;
    use HasTranslationStates;

    protected $guarded = [];
}
