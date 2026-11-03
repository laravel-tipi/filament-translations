<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tipi\Localization\Actions\CreateLocale;
use Tipi\Localization\Data\CreateLocaleData;
use Tipi\Localization\LocaleContexts;
use Tipi\Localization\LocaleRegistry;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\FieldStatus;
use Tipi\Translations\Filament\Enums\TranslationStatus;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Grapes\ListGrapes;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions\ListRegions;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\TranslateWine;
use Tipi\Translations\Filament\TranslationEditor;

beforeEach(function (): void {
    $this->signIn();
});

function wineEditor(Wine $wine, ?Closure $configure = null): RecordEditor
{
    $config = WineResource::getTranslationEditor();

    return new RecordEditor($configure === null ? $config : $configure($config), $wine);
}

it('counts the attributes of the form, not every translatable attribute', function (): void {
    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi', 'description' => '<p>Dry</p>', 'notes' => [['note' => 'Cherry']]],
        // internal_code is translatable but not in the form.
        'ka' => ['name' => 'საფერავი', 'internal_code' => 'X'],
    ]);

    $progress = wineEditor($wine)->progress();

    expect($progress->locale('en')->status())->toBe(TranslationStatus::Complete)
        ->and($progress->locale('ka')->fraction())->toBe('1/3')
        ->and($progress->locale('ka')->status())->toBe(TranslationStatus::Partial)
        ->and($progress->locale('de')->status())->toBe(TranslationStatus::Missing)
        ->and($progress->completeCount())->toBe(1);
});

it('resolves fallback status as readers of the content context see it', function (): void {
    // The panel runs in "admin" (default ka); content is published in "web" (default en).
    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi'],
        'ka' => ['name' => 'საფერავი', 'description' => '<p>მშრალი</p>'],
    ]);

    $editor = wineEditor($wine);

    expect($editor->fieldStatus('name', 'de')[0])->toBe(FieldStatus::Fallback)
        ->and($editor->fieldStatus('name', 'de')[1]->code)->toBe('en')
        // Only Georgian has a description, and "web" doesn't fall back to Georgian.
        ->and($editor->fieldStatus('description', 'de')[0])->toBe(FieldStatus::Missing)
        ->and($editor->fieldStatus('name', 'ka')[0])->toBe(FieldStatus::Translated);

    $admin = wineEditor($wine, fn (TranslationEditor $e) => $e->context('admin'));

    expect($admin->fieldStatus('description', 'en')[0])->toBe(FieldStatus::Fallback)
        ->and($admin->fieldStatus('description', 'en')[1]->code)->toBe('ka');
});

it('shows statuses in the editor', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => '<p>Dry</p>'], 'de' => ['name' => 'Saperawi']]);
    $wine->markTranslationOutdated('de');

    $html = openEditor('page', $wine, 'de')->html();

    expect($html)
        ->toContain('1 of 3 translated')
        ->toContain(__('tipi-filament-translations::editor.panel.outdated'))
        ->toContain(__('tipi-filament-translations::editor.row.translated'))
        ->toContain(__('tipi-filament-translations::editor.row.fallback', ['locale' => 'English']))
        // Nobody has notes: nothing falls back.
        ->toContain(__('tipi-filament-translations::editor.row.missing'));
});

it('marks translations outdated and up to date', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი'], 'de' => ['name' => 'Saperawi']]);

    $editor = openEditor('page', $wine, 'en');
    $panel = fn (string $locale): array => ["tipi-translation-panel-{$locale}", 'form'];

    $editor->livewire->callAction(TestAction::make('markOthersOutdated')->schemaComponent(...$panel('en')));

    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeTrue()
        ->and($wine->fresh()->isTranslationOutdated('de'))->toBeTrue()
        ->and($wine->fresh()->isTranslationOutdated('en'))->toBeFalse();

    $editor->switchTo('ka');
    $editor->livewire->callAction(TestAction::make('markCurrent')->schemaComponent(...$panel('ka')));

    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeFalse();

    $editor->livewire->callAction(TestAction::make('markOutdated')->schemaComponent(...$panel('ka')));

    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeTrue();

    // Saving a change marks the locale current again (Translations' state sync).
    $editor->fill('ka', ['name' => 'საფერავი!'])->save()->assertSaved();

    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeFalse()
        ->and($wine->fresh()->isTranslationOutdated('de'))->toBeTrue();
});

it('offers state actions only to models with translation states', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page());

    $grape = makeRecord(Grape::class, ['en' => ['name' => 'Mtsvane'], 'ka' => ['name' => 'მწვანე']]);

    openEditor('page', $grape, 'ka')->livewire
        ->assertActionDoesNotExist(TestAction::make('markOutdated')->schemaComponent('tipi-translation-panel-ka', schema: 'form'))
        ->assertActionVisible(TestAction::make('deleteTranslation')->schemaComponent('tipi-translation-panel-ka', schema: 'form'));
});

it('shows locale badges that open the editor at that locale', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $html = Livewire::test(ListWines::class)->html();

    expect($html)
        ->toContain('Georgian: 1 of 3 translated.')
        ->toContain("mountAction('translate', JSON.parse('{\\u0022locale\\u0022:\\u0022ka\\u0022}')");

    configureEditor(fn (TranslationEditor $e) => $e->page());

    expect(Livewire::test(ListWines::class)->html())
        ->toContain(e(TranslateWine::getUrl(['record' => $wine, 'locale' => 'ka'])));
});

it('collapses many locales into one summary badge', function (): void {
    makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    expect(Livewire::test(ListWines::class)->html())->toContain('German: 0 of 3 translated.');

    $create = app(CreateLocale::class);

    foreach (['es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch'] as $code => $name) {
        $create->execute(new CreateLocaleData(code: $code, name: $name, nativeName: $name, active: true));
    }

    app(LocaleRegistry::class)->flush();
    app(LocaleContexts::class)->flush();

    // Seven locales: one badge for the record instead of seven.
    expect(Livewire::test(ListWines::class)->html())
        ->toContain('0 of 7 complete')
        ->not->toContain('fi-tipi-tr-column-list');
});

it('costs no queries per row', function (string $list, string $model): void {
    foreach (range(1, 5) as $i) {
        makeRecord($model, ['en' => ['name' => "Item {$i}"], 'ka' => ['name' => "ელემენტი {$i}"]]);
    }

    $count = function () use ($list): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($list);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $five = $count();

    foreach (range(6, 15) as $i) {
        makeRecord($model, ['en' => ['name' => "Item {$i}"]]);
    }

    expect($count())->toBe($five);
})->with([
    'table storage' => [ListWines::class, Wine::class],
    'json storage' => [ListGrapes::class, Grape::class],
    'shared storage' => [ListRegions::class, Region::class],
]);

it('filters records by translation status', function (string $list, string $model): void {
    $complete = makeRecord($model, ['ka' => ['name' => 'სრული', 'description' => 'აღწერა', 'notes' => $model === Wine::class ? [['note' => 'ა']] : ['ა']]]);
    $partial = makeRecord($model, ['ka' => ['name' => 'ნაწილი']]);
    $missing = makeRecord($model, ['en' => ['name' => 'English only']]);

    $filter = fn (string $status) => Livewire::test($list)->filterTable(TranslationStatusFilter::getDefaultName(), ['locale' => 'ka', 'status' => $status]);

    $filter('complete')->assertCanSeeTableRecords([$complete])->assertCanNotSeeTableRecords([$partial, $missing]);
    $filter('incomplete')->assertCanSeeTableRecords([$partial, $missing])->assertCanNotSeeTableRecords([$complete]);
    $filter('missing')->assertCanSeeTableRecords([$missing])->assertCanNotSeeTableRecords([$complete, $partial]);
})->with([
    'table storage' => [ListWines::class, Wine::class],
    'json storage' => [ListGrapes::class, Grape::class],
    'shared storage' => [ListRegions::class, Region::class],
]);

it('filters outdated translations when the model has states', function (): void {
    $outdated = makeRecord(Wine::class, ['en' => ['name' => 'A'], 'ka' => ['name' => 'ა']]);
    $current = makeRecord(Wine::class, ['en' => ['name' => 'B'], 'ka' => ['name' => 'ბ']]);
    $outdated->markTranslationOutdated('ka');

    Livewire::test(ListWines::class)
        ->filterTable('translation_status', ['locale' => 'ka', 'status' => 'outdated'])
        ->assertCanSeeTableRecords([$outdated])
        ->assertCanNotSeeTableRecords([$current]);
});

it('ignores filter values it does not offer', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'A']]);

    Livewire::test(ListGrapes::class)
        ->filterTable('translation_status', ['locale' => 'ka', 'status' => 'outdated'])
        ->assertSuccessful();

    Livewire::test(ListWines::class)
        ->filterTable('translation_status', ['locale' => 'xx', 'status' => 'missing'])
        ->assertCanSeeTableRecords([$wine]);
});
