<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Tests\Fixtures;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tipi\Localization\LocaleContexts;
use Tipi\Localization\Localization;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;

/**
 * What readers get: a wine page in a locale, read the way a website reads
 * translations ($wine->description: the locale, with fallback).
 */
final class PublicSite
{
    public static function routes(): void
    {
        Route::get('/site/{locale}/wines/{wine}', function (string $locale, int $wine): string {
            // A site in the "web" context, in the requested locale.
            app(LocaleContexts::class)->setCurrent('web');
            app(Localization::class)->select($locale);
            $wine = Wine::query()->findOrFail($wine);

            $notes = collect($wine->notes ?? [])->map(fn (array $note): string => '<li>'.e($note['note'] ?? '').'</li>')->implode('');

            return '<!DOCTYPE html><html lang="'.e($locale).'"><head><meta charset="utf-8"><title>'.e($wine->name).'</title></head><body>'
                .'<h1 id="name">'.e($wine->name).'</h1>'
                .'<div id="description">'.$wine->description.'</div>'
                .'<div id="tasting">'.Str::markdown((string) $wine->tasting).'</div>'
                .'<ul id="notes">'.$notes.'</ul>'
                .'</body></html>';
        });
    }
}
