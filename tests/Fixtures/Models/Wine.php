<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasTableTranslations;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * Table storage, with translation states.
 */
#[Translatable(['name', 'description', 'notes', 'internal_code'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations;
    use HasTranslationStates;

    protected $guarded = [];
}
