<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tipi\Localization\Actions\CreateLocale;
use Tipi\Localization\Data\CreateLocaleData;
use Tipi\Localization\LocaleContexts;
use Tipi\Localization\LocaleRegistry;
use Tipi\Translations\Concerns\HasJsonTranslations;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Filament\Editor\RecordEditor;
use Tipi\Translations\Filament\Enums\TranslationStatus;
use Tipi\Translations\Filament\Support\LocaleProgress;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Grape;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Region;
use Tipi\Translations\Filament\Tests\Fixtures\Models\Wine;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\GrapeResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Grapes\ListGrapes;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Regions\ListRegions;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\WineResource;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\ListWines;
use Tipi\Translations\Filament\Tests\Fixtures\Resources\Wines\TranslateWine;
use Tipi\Translations\Filament\TranslationEditor;

/*
 * Completeness is measured against the source: what the source has text
 * for must be translated, nothing else. Derived when read, never stored.
 */

beforeEach(function (): void {
    $this->signIn();
});

function progressOf(Wine $wine, string $locale = 'ka'): LocaleProgress
{
    return (new RecordEditor(WineResource::getTranslationEditor(), $wine))->localeProgress($locale);
}

it('requires what the source has, not what the form requires', function (): void {
    $wine = makeRecord(Wine::class, [
        // The source has no tasting notes: they need no translation.
        'en' => ['name' => 'Saperavi', 'description' => '<p>Traditional Georgian red wine</p>'],
        'ka' => ['name' => 'საფერავი'],
    ]);

    $progress = progressOf($wine);

    expect($progress->translated)->toBe(1)
        ->and($progress->total)->toBe(2)
        ->and($progress->status())->toBe(TranslationStatus::Incomplete);

    $wine->setTranslation('description', 'ka', '<p>ტრადიციული ქართული წითელი ღვინო</p>')->save();

    expect(progressOf($wine->fresh())->status())->toBe(TranslationStatus::Complete);
});

it('counts segments, so a partly translated document is incomplete', function (): void {
    $wine = makeRecord(Wine::class, [
        'en' => [
            'name' => 'Saperavi',
            'tasting' => "Cherry.\n\nPlum.\n\nOak.",
            'notes' => [['id' => 'a', 'note' => 'Dry'], ['id' => 'b', 'note' => 'Red']],
        ],
    ]);

    openEditor('page', $wine, 'ka')
        ->fill('ka', ['name' => 'საფერავი', 'tasting/1' => 'ქლიავი.', 'notes/b/note' => 'წითელი'])
        ->save();

    $progress = progressOf($wine->fresh());

    expect($progress->translated)->toBe(3)
        ->and($progress->total)->toBe(6)
        ->and($progress->fraction())->toBe('3/6');
});

it('treats content without text as nothing to translate', function (mixed $empty): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => $empty]]);

    expect(progressOf($wine)->total)->toBe(1);
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ["  \n "],
    'empty paragraph' => ['<p></p>'],
    'blank paragraphs' => ['<p> </p><p>&nbsp;</p>'],
    'image without alt' => ['<p><img src="/a.jpg"></p>'],
]);

it('treats an item without text as nothing to translate, and its data as structure', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['notes' => [['id' => 'a', 'note' => '', 'score' => 0]], 'content' => []]]);

    expect(progressOf($wine)->total)->toBe(0)
        ->and(progressOf($wine)->status())->toBe(TranslationStatus::NotApplicable);
});

it('follows the source when it changes', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    expect(progressOf($wine)->status())->toBe(TranslationStatus::Complete);

    // A new source text: the translation is incomplete again, without any stored state.
    $wine->setTranslation('description', 'en', '<p>Dry.</p>')->save();

    expect(progressOf($wine->fresh())->status())->toBe(TranslationStatus::Incomplete);

    // The source text removed: complete again.
    $wine = $wine->fresh();
    $wine->setTranslation('description', 'en', null)->save();

    expect(progressOf($wine->fresh())->status())->toBe(TranslationStatus::Complete);
});

it('doesn’t count translations that no longer fit the source', function (): void {
    $grape = makeRecord(Grape::class, ['en' => ['description' => "One.\n\nTwo."], 'ka' => ['description' => "ერთი.\n\nორი.\n\nსამი.\n"]]);

    $progress = (new RecordEditor(GrapeResource::getTranslationEditor(), $grape))->localeProgress('ka');

    expect($progress->translated)->toBe(0)
        ->and($progress->review)->toBe(['description'])
        ->and($progress->needsWork())->toBeTrue();
});

it('measures against the configured source locale', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->sourceLocale(fn (Wine $wine): string => $wine->slug === 'georgian-first' ? 'ka' : 'en'));

    $wine = new Wine(['slug' => 'georgian-first']);
    $wine->fillTranslations(['ka' => ['name' => 'საფერავი', 'description' => '<p>მშრალი</p>'], 'en' => ['name' => 'Saperavi']])->save();

    $editor = new RecordEditor(WineResource::getTranslationEditor(), $wine->fresh());

    expect($editor->sourceLocale()->code)->toBe('ka')
        ->and($editor->locales()->keys()->all())->not->toContain('ka')
        ->and($editor->localeProgress('en')->fraction())->toBe('1/2');
});

it('shows progress and statuses in the editor', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->comparison());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => '<p>Dry</p>'], 'de' => ['name' => 'Saperawi']]);
    $wine->markTranslationOutdated('de');

    $html = openEditor('page', $wine, 'de')->html();

    expect($html)
        ->toContain('1 of 2 translated')
        ->toContain(__('tipi-filament-translations::editor.panel.outdated'))
        ->toContain(__('tipi-filament-translations::editor.row.translated'))
        ->toContain(__('tipi-filament-translations::editor.row.missing'))
        // German has no description: readers see the English one.
        ->toContain(__('tipi-filament-translations::editor.attribute.fallback', ['locale' => 'English']))
        ->toContain(__('tipi-filament-translations::editor.attribute.empty', ['locale' => 'English']));
});

it('marks translations outdated and up to date', function (): void {
    configureEditor(fn (TranslationEditor $e) => $e->page()->localeTabs());

    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi'], 'ka' => ['name' => 'საფერავი']]);

    $editor = openEditor('page', $wine, 'ka');
    $panel = ['tipi-translation-panel-ka', 'form'];

    $editor->livewire->callAction(TestAction::make('markOutdated')->schemaComponent(...$panel));

    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeTrue();

    $editor->livewire->callAction(TestAction::make('markCurrent')->schemaComponent(...$panel));

    expect($wine->fresh()->isTranslationOutdated('ka'))->toBeFalse();
});

it('requires translation states for fields with several texts', function (): void {
    $wine = makeRecord(Wine::class);

    // A plain-text form works without states.
    $plain = new class extends Model implements TranslatableModel
    {
        use HasJsonTranslations;

        protected $table = 'grapes';

        public function getTranslatableAttributes(): array
        {
            return ['name', 'description'];
        }

        public function isTranslatableAttribute(string $attribute): bool
        {
            return in_array($attribute, ['name', 'description'], true);
        }
    };

    $form = fn (string $field) => TranslationEditor::make()->form(fn (Schema $schema) => $schema->components([
        TextInput::make('name'),
        $field === 'markdown' ? MarkdownEditor::make('description') : Textarea::make('description'),
    ]));

    expect((new RecordEditor($form('textarea'), $plain))->attributes())->toBe(['name', 'description'])
        ->and(fn () => (new RecordEditor($form('markdown'), $plain))->attributes())
        ->toThrow(LogicException::class, 'HasTranslationStates');

    expect((new RecordEditor($form('markdown'), $wine))->attributes())->toBe(['name', 'description']);
});

it('shows target locale badges that open the editor at that locale', function (): void {
    $wine = makeRecord(Wine::class, ['en' => ['name' => 'Saperavi', 'description' => '<p>Dry</p>'], 'ka' => ['name' => 'საფერავი']]);

    $html = Livewire::test(ListWines::class)->html();

    expect($html)
        ->toContain('Georgian: 1 of 2 translated.')
        ->not->toContain('English: ')
        ->toContain("mountAction('translate', JSON.parse('{\\u0022locale\\u0022:\\u0022ka\\u0022}')");

    configureEditor(fn (TranslationEditor $e) => $e->page());

    expect(Livewire::test(ListWines::class)->html())
        ->toContain(e(TranslateWine::getUrl(['record' => $wine, 'locale' => 'ka'])));
});

it('collapses many locales into one summary badge', function (): void {
    makeRecord(Wine::class, ['en' => ['name' => 'Saperavi']]);

    $create = app(CreateLocale::class);

    foreach (['es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese'] as $code => $name) {
        $create->execute(new CreateLocaleData(code: $code, name: $name, nativeName: $name, active: true));
    }

    app(LocaleRegistry::class)->flush();
    app(LocaleContexts::class)->flush();

    // Seven target locales: one badge for the record.
    expect(Livewire::test(ListWines::class)->html())
        ->toContain('0 of 7 complete')
        ->not->toContain('fi-tipi-tr-column-list');
});

it('costs no queries per row', function (string $list, string $model): void {
    $content = fn (int $i): array => match ($model) {
        Wine::class => ['name' => "Item {$i}", 'description' => "<p>Item <strong>{$i}</strong></p>", 'notes' => [['id' => 'a', 'note' => 'x']]],
        Grape::class => ['name' => "Item {$i}", 'description' => "Item **{$i}**"],
        default => ['name' => "Item {$i}", 'notes' => ['x']],
    };

    foreach (range(1, 5) as $i) {
        makeRecord($model, ['en' => $content($i), 'ka' => ['name' => "ელემენტი {$i}"]]);
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
        makeRecord($model, ['en' => $content($i)]);
    }

    expect($count())->toBe($five);
})->with([
    'table storage' => [ListWines::class, Wine::class],
    'json storage' => [ListGrapes::class, Grape::class],
    'shared storage' => [ListRegions::class, Region::class],
]);

it('filters records by translation status against the source', function (string $list, string $model): void {
    $complete = makeRecord($model, ['en' => ['name' => 'Full', 'description' => 'Dry'], 'ka' => ['name' => 'სრული', 'description' => 'მშრალი']]);
    // Only a name in the source: translating the name completes it.
    $short = makeRecord($model, ['en' => ['name' => 'Short'], 'ka' => ['name' => 'მოკლე']]);
    $partial = makeRecord($model, ['en' => ['name' => 'Part', 'description' => 'Dry'], 'ka' => ['name' => 'ნაწილი']]);
    $missing = makeRecord($model, ['en' => ['name' => 'English only']]);

    $filter = fn (string $status) => Livewire::test($list)->filterTable(TranslationStatusFilter::getDefaultName(), ['locale' => 'ka', 'status' => $status]);

    $filter('complete')->assertCanSeeTableRecords([$complete, $short])->assertCanNotSeeTableRecords([$partial, $missing]);
    $filter('incomplete')->assertCanSeeTableRecords([$partial, $missing])->assertCanNotSeeTableRecords([$complete, $short]);
    $filter('missing')->assertCanSeeTableRecords([$missing])->assertCanNotSeeTableRecords([$complete, $short, $partial]);
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

    // The source is not a target.
    Livewire::test(ListWines::class)
        ->filterTable('translation_status', ['locale' => 'en', 'status' => 'missing'])
        ->assertCanSeeTableRecords([$wine]);
});

it('never reports content it can’t translate as complete', function (): void {
    $wine = makeRecord(Wine::class, [
        'en' => ['name' => 'Saperavi', 'notes' => [['note' => 'Cherry']]],
        'ka' => ['name' => 'საფერავი'],
    ]);

    $progress = progressOf($wine);

    // The notes have no ids: they can't be translated, and say so.
    expect($progress->fraction())->toBe('1/1')
        ->and($progress->status())->toBe(TranslationStatus::Incomplete)
        ->and($progress->unsupported)->toBe(['notes'])
        ->and($progress->summary())->toContain(__('tipi-filament-translations::editor.progress.unsupported'));
});
