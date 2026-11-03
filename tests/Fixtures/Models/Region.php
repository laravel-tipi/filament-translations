<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Attributes\Translatable;
use Tipi\Translations\Concerns\HasSharedTableTranslations;
use Tipi\Translations\Concerns\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;

/**
 * Shared table storage, with translation states. Scoped to its owner.
 */
#[Translatable(['name', 'description', 'notes'])]
class Region extends Model implements TranslatableModel
{
    use HasSharedTableTranslations;
    use HasTranslationStates;

    protected $guarded = [];
}
