<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasLocalizedTranslations;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Actions\DeleteTranslationAction;
use Tipi\Translations\Filament\Actions\EditTranslationAction;
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;

final class TestTranslatableModel extends Model implements TranslatableModel
{
    use HasLocalizedTranslations;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function getTranslatableAttributes(): array
    {
        return ['name'];
    }
}

it('uses stable default action names', function (): void {
    expect(TranslateAction::getDefaultName())->toBe('translate')
        ->and(EditTranslationAction::getDefaultName())->toBe('edit_translation')
        ->and(DeleteTranslationAction::getDefaultName())->toBe('delete_translation');
});

it('resolves an explicitly configured translatable record', function (): void {
    $record = new TestTranslatableModel;
    $record->setAttribute($record->getKeyName(), 'wine-1');

    $action = TranslateAction::make()
        ->translatable($record);

    expect($action->getTranslatableRecord())->toBe($record)
        ->and($action->getTranslatableRecordTitle())->toBe('wine-1');
});

it('supports a custom record title', function (): void {
    $record = new TestTranslatableModel;

    $action = DeleteTranslationAction::make()
        ->translatable($record)
        ->recordTitle('Saperavi');

    expect($action->getTranslatableRecordTitle())->toBe('Saperavi');
});

it('evaluates locale codes configured with closures', function (): void {
    $action = EditTranslationAction::make()
        ->localeCode(fn (): string => 'ka');

    expect($action->getLocaleCode())->toBe('ka');
});

it('requires a locale code when editing a translation', function (): void {
    EditTranslationAction::make()->getLocaleCode();
})->throws(
    LogicException::class,
    'Translation locale code must not be null.',
);

it('returns null when the configured record is not translatable', function (): void {
    $action = TranslateAction::make();

    expect($action->getTranslatableRecord())->toBeNull();
});

it('uses the package table column views', function (): void {
    $column = TranslationsColumn::make('translations');

    expect($column->getView())->toBe(
        'tipi-filament-translations::tables.columns.translations-column',
    )->and($column->getHeaderView())->toBe(
        'tipi-filament-translations::tables.columns.translations-column-header',
    );
});
