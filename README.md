# Laravel Tipi Filament Translations

Translation editors for Filament, built on [`laravel-tipi/translations`](https://github.com/laravel-tipi/translations).

```php
class WineResource extends Resource
{
    use Translatable;

    public static function translationForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            RichEditor::make('description'),
        ]);
    }
}
```

- **One form, every locale.** Standard Filament fields, defined once.
- **Two layouts.** A side-by-side **comparison** editor (read-only source next to the editable target), or a **locale-focused** form switched with tabs or a select.
- **Two presentations.** A **page** with its own URL, or a **modal** or slide-over. Any layout works in any presentation.
- **Exact values.** Editors see and save exactly what is stored for each locale, never fallback text.
- **Status at a glance.** Per-field, per-locale and per-record progress, outdated translations, table badges and filters.
- **Safe saves.** One transaction for every changed locale, conflict detection, and locale-level permissions.
- **Every storage.** JSON columns, translation tables and the shared table work the same.

## Contents

- [Requirements](#requirements) · [Installation](#installation)
- [Minimal setup](#minimal-setup)
- [The translation form](#the-translation-form)
- [Choosing an editor](#choosing-an-editor): presentation, layout, navigation, the four combinations
- [Panel defaults and overrides](#panel-defaults-and-overrides)
- [Locales and contexts](#locales-and-contexts)
- [Status and completeness](#status-and-completeness): editor, table column, filter, translation states
- [Saving](#saving): the editing contract
- [Validation](#validation)
- [Authorization](#authorization)
- [Storage independence](#storage-independence)
- [Advanced](#advanced)

## Requirements

- PHP 8.5+, Laravel 13, Filament 5.10+
- `laravel-tipi/translations` 0.2+ and `laravel-tipi/localization` 0.2+

## Installation

```bash
composer require laravel-tipi/filament-translations
php artisan filament:assets
```

The service provider is discovered automatically. The plugin is optional: register it only to set [panel defaults](#panel-defaults-and-overrides).

## Minimal setup

A translatable model (see the Translations documentation):

```php
#[Translatable(['name', 'description'])]
class Wine extends Model implements TranslatableModel
{
    use HasTableTranslations;
}
```

Add the `Translatable` concern and a translation form to the resource, then the action and the column to its table:

```php
use Tipi\Translations\Filament\Actions\TranslateAction;
use Tipi\Translations\Filament\Resources\Concerns\Translatable;
use Tipi\Translations\Filament\Tables\Columns\TranslationsColumn;
use Tipi\Translations\Filament\Tables\Filters\TranslationStatusFilter;

class WineResource extends Resource
{
    use Translatable;

    public static function translationForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            RichEditor::make('description'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug'),
                TranslationsColumn::make(),
            ])
            ->filters([TranslationStatusFilter::make()])
            ->recordActions([TranslateAction::make(), EditAction::make()]);
    }
}
```

That's all: **Translate** opens the comparison editor in a modal. The column shows each locale's progress; clicking a locale opens the editor at that locale.

To translate from the edit or view page too, add the same action to its header:

```php
protected function getHeaderActions(): array
{
    return [TranslateAction::make(), DeleteAction::make()];
}
```

## The translation form

`translationForm()` returns ordinary Filament components named after translatable attributes. The editor builds it **once per locale**, binds each copy to that locale, and in the comparison layout pairs every field with a read-only copy bound to the source locale.

```php
public static function translationForm(Schema $schema): Schema
{
    return $schema->components([
        TextInput::make('name')->required(),
        Textarea::make('excerpt')->rows(3),
        RichEditor::make('description'),
        Section::make('SEO')->schema([
            TextInput::make('seo_title')->maxLength(60),
            Textarea::make('seo_description')->maxLength(160),
        ]),
        Repeater::make('tasting_notes')->schema([
            TextInput::make('note')->required(),
        ]),
    ]);
}
```

- **Standard fields work as they do anywhere**, because each field hydrates and dehydrates its own state: rich text is stored as HTML (or JSON with `->json()`), repeaters and tags as the arrays they produce. The suite covers text inputs, textareas, rich editors, tags inputs and repeaters (with nested validation); other fields work the same way when their state fits the attribute. Use array-valued attributes (JSON storage, shared storage, or an `array` cast on the translation model) for repeaters, builders and tags.
- **Layout components** (`Section`, `Grid`, `Group`, `Fieldset`, `Tabs`) are kept. Their fields are paired one by one in the comparison layout, so source and target rows line up inside sections. Give layout children as arrays: children built by a closure are built before the editor exists.
- **A component with its own state path** (a field, or a `Group::make()->statePath('seo')` over a JSON attribute) is edited as one unit: a repeater is compared with the source repeater as a whole.
- **Names must be translatable attributes.** A field that isn't (or isn't declared in `#[Translatable]`) throws a `LogicException` when the editor is built. Translatable attributes left out of the form are never written by the editor and don't count towards completeness.
- **Per-locale behavior**: the form receives the locale being built, so rules can depend on it.

```php
public static function translationForm(Schema $schema, ?Locale $locale = null): Schema
{
    return $schema->components([
        TextInput::make('name')
            ->required($locale?->isDefault() ?? true)  // required in the default locale only
            ->maxLength($locale?->code === 'de' ? 40 : 60),
    ]);
}
```

`Tipi\Support\Locale` carries the code, names, text direction and flags of the locale.

## Choosing an editor

Presentation and layout are independent. Every combination uses the same form, locales, validation and saving.

| | Presentation | |
|---|---|---|
| `page()` | A resource page: `/wines/{record}/translations?locale=ka&source=en`. Deep links, reloads, browser history, breadcrumbs, unsaved-changes warnings. | |
| `modal()` | A modal over the table or page. **Default.** | |
| `slideOver()` | A modal sliding over from the side. | |

| | Layout | |
|---|---|---|
| `comparison()` | Each target field beside the same field of a read-only source locale. **Default.** For professional translation. | |
| `localeTabs()` | One locale at a time, switched with tabs. Like a conventional multilingual form. | |
| `localeSelect()` | The same, switched with a select: compact, for many locales. | |

Tabs and select are two navigations of the same layout. The comparison layout switches its **target** locale with tabs up to five locales and a select above; force one with `comparison(LocaleNavigation::Select)`.

### The four combinations

```php
// 1. Page + comparison: a translation workspace.
$editor->page()->comparison();

// 2. Modal + comparison: translate from the table without leaving it.
$editor->modal()->comparison();

// 3. Page + locale tabs: a multilingual form with its own URL.
$editor->page()->localeTabs();

// 4. Modal + locale select: quick edits across many locales.
$editor->modal()->localeSelect();
```

### The page

The page presentation needs a page class registered on the resource, as for `EditRecord`:

```php
use Tipi\Translations\Filament\Resources\Pages\TranslateRecord;

class TranslateWine extends TranslateRecord
{
    protected static string $resource = WineResource::class;
}
```

```php
public static function getPages(): array
{
    return [
        'index' => ListWines::route('/'),
        'edit' => EditWine::route('/{record}/edit'),
        'translations' => TranslateWine::route('/{record}/translations'),
    ];
}
```

`TranslateAction` and the column's locale badges then link to it. The URL follows the shown locale and source (`?locale=ka&source=en`), so editors can share and bookmark links and use the back button. **Save** keeps the editor open; **Back** returns to the previous page (the browser's history, so a table keeps the filters and search in its URL).

### The modal

The modal is Filament's own action modal: 7xl wide for the comparison layout and 4xl for the locale-focused one, with a sticky header and footer. Clicking outside doesn't close it, so work isn't lost by accident. Customize it like any action:

```php
TranslateAction::make()->modalWidth(Width::Full)->slideOver();
```

## Panel defaults and overrides

Settings apply in this order; each level can change any of them.

```php
// 1. Panel: every editor of the panel.
FilamentTranslationsPlugin::make()
    ->editor(fn (TranslationEditor $editor) => $editor->page()->comparison()->context('web'));

// 2. Resource: its editors.
public static function translationEditor(TranslationEditor $editor): TranslationEditor
{
    return $editor->modal()->localeTabs();
}

// 3. Action: one entry point, e.g. a quick modal on a table while the resource uses the page.
TranslateAction::make()->editor(fn (TranslationEditor $editor) => $editor->modal());
```

Panels are configured independently, so the same resource can use a page on one panel and a modal on another.

The editor's methods:

| Method | |
|---|---|
| `page()`, `modal()`, `slideOver()` | Presentation. |
| `comparison(?LocaleNavigation $navigation = null)`, `localeTabs()`, `localeSelect()` | Layout and navigation. |
| `context(?string $context)` | The locale context the content is published in. |
| `locales(array\|Closure\|null $locales)` | The locales to edit instead of the context's. |
| `inactiveLocales(bool $condition = true)` | Also offer inactive locales. |
| `sourceLocale(string\|Closure\|null $locale)` | The source the comparison opens with. |
| `form(Closure $form)` | The form, for records outside a translatable resource. |

## Locales and contexts

The editor edits the locales of the **content** locale context, not the panel's interface locale. An admin panel in Georgian can edit English, German and Arabic content.

- **Default**: the locales of Localization's default context (usually `web`, where content is published), in display order.
- `->context('web')`: another context. Its locales are offered, its default is the default source, and fallback status is shown as that context's readers see it.
- `->locales(['en', 'ka', 'de'])` or a closure receiving the record: an explicit list. Any registry locale may be listed, active or not.
- `->inactiveLocales()`: add the registry's inactive locales, to prepare content before a locale goes live. They are marked **Inactive** everywhere.

The source locale of the comparison layout defaults to the configured `sourceLocale()`, then the context default, then the global default (whichever has content). Editors can choose any locale with content as the source. If the chosen source has no value for a field, the source field is empty: the editor never substitutes another locale.

Locales are shown with their names, native names and text direction. Inputs get `lang` and `dir` (`dir="rtl"` for Arabic), so right-to-left content is edited right-to-left while labels keep the interface's direction.

Filament Localization isn't required. When you use it, its panel context drives the interface; this package's context setting drives content.

## Status and completeness

Completeness counts the attributes of the translation form.

| Field status | |
|---|---|
| **Translated** | A value is stored for the locale. |
| **Fallback** | Nothing is stored; readers of the content context see another locale (shown: "readers see English"). |
| **Missing** | Nothing is stored and nothing falls back. |

| Locale status | |
|---|---|
| **Complete** | Every attribute is translated. |
| **Incomplete** | Some are. |
| **Not translated** | None are. |
| **Outdated** | Marked for review (models with translation states). Independent of completeness. |

Fallback is never counted as translated, and fields are never filled with fallback text.

### In the editor

The locale navigation shows each locale's progress ("4/6"), an outdated clock and validation errors. The panel header shows the progress and whether the locale is outdated or inactive. Under every field, its status, and in the comparison layout a **Copy source** action that copies the stored source value, formatting and items included.

### In the table

`TranslationsColumn` shows a compact badge per locale: green when complete, amber when incomplete or outdated, gray when missing, with a tooltip. A badge opens the editor at its locale through the table's `TranslateAction` (by default `translate`; `->translateAction('name')` for another). With more than six locales the column shows one summary badge instead (`->limit(10)` to change it).

The column eager loads `translations` (and `translationStates`), so it costs no queries per row on any storage.

### Filtering

`TranslationStatusFilter` filters by a locale's status: not translated, incomplete, complete and, with translation states, outdated. It uses the Translations query scopes, so it works on every storage.

### Translation states

With `HasTranslationStates` on the model, the editor's locale menu offers:

- **Mark as outdated** and **Mark as up to date**;
- **Mark other languages as outdated**, after changing a source text;
- **Change status**, when `translations.states.status_enum` is configured.

Saving a change to a locale marks it up to date, as Translations does on every save. Without states, these actions and the outdated filter aren't shown.

## Saving

The editing contract, the same in every presentation and layout:

1. **Exact values.** Fields are filled with exactly what is stored for their locale (`getTranslation($attribute, $locale)`), never with fallback values.
2. **Locales load when opened.** Switching locales loads the new one and keeps unsaved changes in the others.
3. **One save for every changed locale.** **Save** validates and writes every opened locale whose fields changed, in one `save()` of the model: one transaction, unchanged values not written. Locales only looked at are neither validated nor saved. The source, other locales and attributes outside the form are never written.
4. **Empty means not translated.** Clearing a field (empty text, an empty rich editor, an empty repeater) removes the value, so readers see the fallback. The editor never stores empty strings.
5. **Conflicts are refused.** If someone else saved a locale you changed after you opened it, nothing is saved, your changes stay in the form, and a notification names the locale. Locales you didn't change never conflict.
6. **Validation failures keep your input.** The editor shows the first locale with errors; the navigation marks every locale with errors.

On the page, leaving with unsaved changes asks for confirmation (with the panel's `unsavedChangesAlerts()`); switching locales or the source isn't a change. Modals warn before the browser leaves the page, like every Filament action.

**Delete translation** in a locale's menu removes every value of that locale, after confirmation.

## Validation

Use field validation as in any Filament form: `required()`, `maxLength()`, `rules()`, custom rules. It applies to each locale separately, and the form's [locale parameter](#the-translation-form) allows rules per locale. Nested fields (repeaters, builders) are validated normally. Errors appear on the field of the locale they belong to.

The read-only source fields are never validated.

## Authorization

Define a `translate` ability to control who translates which locales. It receives the record and the locale code:

```php
class WinePolicy
{
    public function translate(User $user, Wine $wine, string $locale): bool
    {
        return $user->isAdmin() || in_array($locale, $user->languages, true);
    }

    // Optional; defaults to translate().
    public function deleteTranslation(User $user, Wine $wine, string $locale): bool
    {
        return $user->isAdmin();
    }
}
```

Without `translate`, translating requires the resource's edit permission (`canEdit()`, usually `update`).

- Editors only see, open and save the locales they may translate. Users who may translate none don't see `TranslateAction`, and the page returns 403.
- Every request is reauthorized on the server: changing Livewire state, locale codes or action arguments can't load, save, delete or change states of other locales; a forged locale fails the whole save with 403.
- Records are resolved through the resource's query, as Filament does, so tenancy and query scopes apply to the page and to table actions.
- Translation state actions require `translate` for their locale.

## Storage independence

JSON, table and shared storage behave identically: the editor reads with `getTranslation()` and `getTranslatedLocales()`, writes with `setTranslations()` and `save()`, deletes with `forgetTranslations()`, and queries with `whereHasTranslation()` and `whereDoesntHaveTranslation()`. Nothing in this package depends on where translations are stored, and models with different storages work side by side.

## Advanced

### Records outside a translatable resource

Give the action its own form:

```php
TranslateAction::make()
    ->translationForm(fn (Schema $schema) => $schema->components([
        TextInput::make('label')->required(),
    ]))
    ->editor(fn (TranslationEditor $editor) => $editor->localeTabs());
```

Use `->resource(OtherResource::class)` when a page's records belong to another translatable resource (for example in a relation manager).

### Opening a locale

```php
TranslateAction::make()->locale('ka');
```

### Customizing the page

`TranslateRecord` is a Filament resource page: override `$title`, `$breadcrumb`, `getHeaderActions()`, `getSavedNotification()` or `canAccess()` as usual.

### Translations and views

```bash
php artisan vendor:publish --tag=tipi-filament-translations-translations
php artisan vendor:publish --tag=tipi-filament-translations-views
```

The stylesheet uses Filament's color variables and container queries: the comparison layout switches between side by side and stacked on the editor's own width, so pages, modals and slide-overs behave the same.

## Development

```bash
composer check                                       # Pint + the suite
vendor/bin/testbench workbench:build && vendor/bin/testbench serve
```

The workbench serves the test fixtures as an app: sign in at `/admin/login` as `editor@example.com` / `password`.

## License

MIT.
