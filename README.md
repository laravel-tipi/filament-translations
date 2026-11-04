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

- **Translators translate text, authors keep the structure.** Plain text, Markdown, rich text, repeaters and builders are split into text segments, each edited in a plain textarea. Formatting and links are numbered tags translators can move (`This is <b1>excellent</b1> wine`), never markup they can break. Items, blocks, URLs, images and settings stay the source's.
- **Checked reconstruction.** Every translated value is rebuilt from the source's structure and read back; a text the format can't express is refused with a reason, never stored differently.
- **Completeness from the source.** What the source has text for needs translating, nothing else. Drafts are always saved; progress ("7 of 10 published") follows the source as it changes and describes what readers see, with drafts shown apart.
- **No lost updates.** Saves merge with what others saved meanwhile (other locales, fields, paragraphs, items) inside one locked transaction, and refuse what overlaps, keeping the translator's text.
- **Two layouts, three presentations.** The source (or another language) beside the translation, or one locale at a time with tabs or a select; on a page, in a modal or a slide-over.
- **Status at a glance.** Per-segment, per-locale and per-record progress, outdated translations, table badges and filters.
- **Every storage.** JSON columns, translation tables and the shared table work the same.

## Contents

- [Requirements](#requirements) · [Installation](#installation)
- [Minimal setup](#minimal-setup)
- [The translation form](#the-translation-form): declaring content, supported fields
- [Translating text](#translating-text): segments, drafts and publishing, tags, placeholders, items, what is translated
- [Choosing an editor](#choosing-an-editor): presentation, layout, navigation, the four combinations
- [Panel defaults and overrides](#panel-defaults-and-overrides)
- [Locales and contexts](#locales-and-contexts)
- [Status and completeness](#status-and-completeness): what counts, editor, table column, filter, translation states
- [Saving](#saving): the editing contract
- [Concurrent editing](#concurrent-editing): merges, conflicts, guarantees
- [Validation](#validation)
- [Authorization](#authorization)
- [Storage independence](#storage-independence)
- [Advanced](#advanced)

## Requirements

- PHP 8.5+, Laravel 13, Filament 5.10+
- `laravel-tipi/translations` 0.2+ (with `lockTranslationsForUpdate()`, drafts and sources) and `laravel-tipi/localization` 0.2+
- `HasTranslationStates` on models whose translation form has fields with several texts (Markdown, rich text, repeaters, builders): it holds drafts and source fingerprints
- `ext-dom` (PHP's HTML5 parser) and `league/commonmark` 2.7+, which Laravel already requires

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

`translationForm()` **declares** the translatable content with the fields that author it. The editor reads it to know each attribute's format and structure; it never shows these fields to translators.

```php
public static function translationForm(Schema $schema, ?Locale $locale = null): Schema
{
    return $schema->components([
        TextInput::make('name')->maxLength($locale?->code === 'de' ? 40 : 60),
        RichEditor::make('description'),
        MarkdownEditor::make('tasting_notes'),
        Repeater::make('features')->schema([
            ItemId::make(),                   // a stable item id: see "Items"
            TextInput::make('title'),
            Textarea::make('text'),
            FileUpload::make('icon'),         // not text: kept from the source
        ]),
        Builder::make('content')->blocks([
            Block::make('heading')->schema([TextInput::make('text'), Select::make('level')->options([...])]),
            Block::make('quote')->schema([Textarea::make('text'), TextInput::make('author')]),
        ]),
    ]);
}
```

| Field | Translated as |
|---|---|
| `TextInput` | one single-line segment; `maxLength()` / `minLength()` / `length()` apply |
| `Textarea` | one multi-line segment; length limits apply |
| `MarkdownEditor` | a segment per block (paragraph, heading, list item, quote, table cell) and per image description |
| `RichEditor` | the same, for its HTML; TipTap JSON (`->json()`) is converted with the field's own editor |
| `Repeater` | its items' text fields; `->simple()` repeaters item by item |
| `Builder` | its blocks' text fields, by block type |

- **Inside items**, only text fields are translated. Every other value (selects, toggles, files, numbers, emails, URLs, undeclared keys, blocks of undeclared types) is the source's.
- **Layout components** (`Section`, `Grid`, `Group`, `Tabs`) only group the declaration. A layout component with a state path (`Group::make([...])->statePath('seo')`) declares keys of one attribute.
- **Names must be translatable attributes**, and top-level fields must be one of the fields above; anything else throws a `LogicException` when the editor is built. Translatable attributes left out of the form are never written and don't count towards completeness.
- **`required()` doesn't block drafts.** What has to be translated follows from the source's content, not from the form's rules.
- The form receives the target locale, so limits can depend on it (as for `name` above).

Why not derive this from the resource's `form()`? Content forms mix translatable and other fields, depend on the operation and the record, and are costly to build for every table row. The declaration is explicit, cheap and usually a copy of the translatable part of the content form.

## Translating text

### Segments

The **source** decides what there is to translate: each text of its content is a segment. In the comparison layout each segment shows the source text beside a textarea; in the locale-focused layout above it. Segments sit under their attribute, labelled with where they are ("2 · Quote › Author", "List item").

Blank means not translated; the source's text is never stored in its place.

### Drafts and publishing

A field is **published** only when all of its texts are translated. Until then the translator's work is saved as an unpublished **draft** (Translations' drafts, on `HasTranslationStates`), so readers never see a half-translated document with gaps:

| The translator saves… | Readers of the locale see | The editor shows |
|---|---|---|
| every text of a field | the translation | the translation |
| some texts of a field that has no translation yet | the fallback (e.g. the English field, whole) | the draft: "Unpublished draft… Readers see English meanwhile." |
| some texts of an update to a published field | the published translation, unchanged | the draft: "…Readers see the published translation meanwhile (5 of 5 texts)." |
| completes the draft | the new translation; the draft is gone | the translation |
| clears every text | a draft cleared: the published translation stays; else the translation is removed | — |

- The draft keeps the source's structure, with untranslated blocks empty, so it lines up with the source when reopened. It is never read as a translation: not by `$model->description`, fallback, queries, the table filter or serialization.
- Statuses, progress and freshness describe the **published** translation: what readers see. Drafts are reported beside it ("Draft: 6 of 7" in the panel, a pencil on the table badge, "Unpublished drafts: 6 of 7 texts translated" in tooltips) and never count as translated.
- A save publishes a field only if the result shows readers no outdated text they don't see already (see [Freshness](#freshness)). A complete draft with a text whose source changed since stays a draft until that text is updated or confirmed **Still correct**. Texts readers already see are no obstacle: improving one paragraph of a published translation publishes it even if another paragraph is outdated (it stays flagged).
- Publishing happens only when a translator saves the field. A change to the source never publishes a draft: when the source drops the only untranslated texts (say, an item is removed), the draft says so and offers **Publish when saving**. Requested for a draft someone else changed since, or whose source changed since, nothing is published.
- A field with a single text (`TextInput`, `Textarea`) can't be partial: it is published or not. A form with fields of several texts (Markdown, rich text, repeaters, builders) needs `HasTranslationStates` on the model; building the editor without it throws a `LogicException`.

### Tags

Inline formatting is shown as numbered tags:

```
Source:       This is <b1>excellent</b1> wine, see <a2>the map</a2>. Run <code3/>.
Translation:  <a2>რუკაზე</a2> ნაჩვენები <b1>შესანიშნავი</b1> ღვინოა. გაუშვით <code3/>.
```

- **Pairs** (`<b1>…</b1>`) are bold, italic, links and other formatting around text. Translators may move them, change their order and nest them differently, so word order can change.
- **Voids** (`<code3/>`) are content kept as is: inline code, images, merge tags, embeds, formatting without text (a linked image).
- Every tag of the source must be kept exactly once, well nested; a missing, unknown, doubled or crossing tag is refused with a reason. Tags show their meaning on hover ("Link: https://…").
- Attributes are the source's: a link points where the source's link points, also after the source changes it.
- Typed markup is text: `<script>` in a translation is stored as `&lt;script&gt;`.
- **Copy** puts the source (or reference) text, tags included, into the textarea, to translate around them.

Rebuilding is checked: the rebuilt value is parsed again and must have the source's structure and exactly the translated texts. When Markdown can't express a translation (emphasis directly between a letter and punctuation, `ა**"x"**ბ`, isn't emphasis in Markdown), the segment is refused and the message suggests moving the tag.

### Placeholders

`{{ name }}`, `{name}`, `:name` and `%s` / `%1$d` must appear in a translation as often as in the source. Change the patterns, or check none:

```php
$editor->placeholders(['/%[a-z_]+%/']);
$editor->placeholders([]);
```

### Items

Repeater and builder items are identified by their **id** field (`id` by default, `->itemKey('uuid')` for another), so translations follow their items when the source adds, removes or reorders them, also while someone is translating. Filament stores items without ids: add `ItemId` to the item schema of the content form **and** the translation form (and to every block of a builder):

```php
use Tipi\Translations\Filament\Forms\Components\ItemId;

Repeater::make('features')->schema([ItemId::make(), TextInput::make('title')]);
```

`ItemId` gives new items a ULID, and items saved before one when the content form loads them; saving the record in its form fixes them all.

Positions are never trusted where items can move:

- A repeater or builder that can add, delete or reorder items (Filament's default) must declare the id field; building the editor without it throws a `LogicException` naming the field and the fix. A **fixed** list (`->addable(false)->deletable(false)->reorderable(false)`), and only that, is matched by position. Simple repeaters (`->simple()`) can't hold ids, so they must be fixed.
- An item of the source without an id, or two items with the same id (a cloned item), make the field untranslatable, with the reason shown, until the content form fixes them. Nothing is guessed.
- A stored translated item is used only when its id and its data other than text (block type, image, settings) match the source's item. Stored items without an id are shown for reference only.
- Ids apply at every level: nested repeaters inside items are matched by their own ids.

When the source's structure changed after a translation was saved (a paragraph added, an item's type changed), the texts that no longer fit are not shown in its fields: they are listed under the attribute, to copy from. Untouched, the stored translation stays as it is.

### What is translated, and what isn't

| Content | |
|---|---|
| Text of paragraphs, headings, list items, quotes, table cells, captions | translated |
| `alt` texts of images and `title` texts (tooltips) of any element, in HTML and Markdown | translated, as their own segments; untranslated ones are left empty, never copied |
| HTML blocks in Markdown | translated with the HTML rules; a block the HTML parser would complete or reorder (its closing tag in another block) makes the field untranslatable, with the reason |
| Inline HTML in Markdown | kept as a void tag; inline HTML with text in `title`, `alt`, `aria-label` or `placeholder` makes the field untranslatable |
| Rich editor custom blocks | declared with `customBlocks()`: their text config keys are translated, the rest kept; a custom block with text that isn't declared makes the field untranslatable |
| Code (`<pre>`, `<code>`, fenced and inline code), merge tags, mentions, images, embeds, links' targets | intentionally kept from the source |
| Non-text fields inside items (select, toggle, file, number, email, URL inputs) | intentionally kept from the source |
| Tags input, key-value, select fields as whole attributes | can't be declared: a `LogicException` |

```php
$editor->customBlocks([
    'hero' => ['heading', 'subheading'],  // config keys holding text (dot notation for nested keys)
    'divider' => [],                      // no text
]);
```

A field that can't be translated is shown with its reason and counts as **incomplete**, never as complete or "nothing to translate": user-facing text is never left out of translation or completeness unnoticed.

## Choosing an editor

Presentation and layout are independent. Every combination uses the same form, locales, validation and saving.

| | Presentation | |
|---|---|---|
| `page()` | A resource page: `/wines/{record}/translations?locale=ka&reference=de`. Deep links, reloads, browser history, breadcrumbs, unsaved-changes warnings. | |
| `modal()` | A modal over the table or page. **Default.** | |
| `slideOver()` | A modal sliding over from the side. | |

| | Layout | |
|---|---|---|
| `comparison()` | Each segment's source text (or another language's, as reference) beside its translation. **Default.** | |
| `localeTabs()` | One locale at a time, switched with tabs, with the source text above each textarea. | |
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

`TranslateAction` and the column's locale badges then link to it. The URL follows the shown locale and reference (`?locale=ka&reference=de`), so editors can share and bookmark links and use the back button. **Save** keeps the editor open; **Back** returns to the previous page (the browser's history, so a table keeps the filters and search in its URL).

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
| `sourceLocale(string\|Closure\|null $locale)` | The canonical source: what is translated, never a target. The global default locale by default; a closure receives the record. |
| `placeholders(?array $patterns)` | The placeholders translations must keep. |
| `itemKey(?string $key)` | The id field of repeater and builder items (`id`). |
| `customBlocks(array $blocks)` | The text config keys of rich editor custom blocks, by block id. |
| `form(Closure $form)` | The form, for records outside a translatable resource. |

## Locales and contexts

The editor edits the locales of the **content** locale context, not the panel's interface locale. An admin panel in Georgian can edit English, German and Arabic content.

- **Default**: the locales of Localization's default context (usually `web`, where content is published), in display order.
- `->context('web')`: another context. Its locales are offered, and fallback status is shown as that context's readers see it.
- `->locales(['en', 'ka', 'de'])` or a closure receiving the record: an explicit list. Any registry locale may be listed, active or not.
- `->inactiveLocales()`: add the registry's inactive locales, to prepare content before a locale goes live. They are marked **Inactive** everywhere.

**The canonical source** is the global default locale, the language content is authored in, unless `sourceLocale()` says otherwise (per record with a closure). It decides which texts need translating and the structure translations keep, and it is never a target: it is edited in the content form. The comparison layout can show another language **beside** the target (**Show beside**), for translators who translate better from it; that never changes what is translated or what counts as complete. Where the reference language has no text for a segment, the editor says so: it never substitutes another locale.

Five locales are in play and kept apart: the global default, the context's default, the panel's interface locale, the reference shown beside the target, and the target.

Locales are shown with their names, native names and text direction. Inputs get `lang` and `dir` (`dir="rtl"` for Arabic), so right-to-left content is edited right-to-left while labels keep the interface's direction.

Filament Localization isn't required. When you use it, its panel context drives the interface; this package's context setting drives content.

## Status and completeness

Completeness is measured against the source's content, segment by segment, when it is read. Nothing is stored, so it stays right when the source changes.

- A segment counts when the source has text for it. An empty or optional source field, an empty Markdown or rich text document (`<p></p>`), whitespace, an item without text and an image without alt text need no translation.
- Text is any string or number with non-whitespace content; `null`, `false` and arrays are not text.
- Stored translations that no longer line up with the source (its structure changed) don't count, and the locale is flagged for review.
- Translations are never required: drafts save, and their progress is shown beside the status.

A locale's status describes its published translations, what readers see:

| Locale status | |
|---|---|
| **Complete** | Every segment with source text is published. |
| **Incomplete** | Some are. |
| **Not translated** | None are (drafts don't count). |
| **Nothing to translate** | The source has no text. |
| **Outdated** | Published texts translated from source texts that changed since, or marked for review. Independent of completeness. |
| **Has drafts** | Unpublished drafts exist. Independent of the rest. |

Per attribute, the editor shows its progress and, while nothing is published, what readers of the content context see instead ("readers see English"). Fallback is never counted as translated, and fields are never filled with fallback text.

### Freshness

Completeness asks whether each source text has a translation; freshness asks whether the source text changed since it was translated. When a text is saved (published or draft), its segment records a fingerprint of the source text it was translated from (Translations' `setTranslationSource()`, one 16-character hash per segment). A translated segment is **outdated** when the source text's fingerprint differs; this is derived when read and holds across requests and editing sessions.

- Only text counts: link targets, images, item settings and model columns changing don't make anything outdated. Formatting changes do (they are part of the text).
- Each segment is judged on its own: rewording one paragraph or one item flags that text only.
- Saving other texts never refreshes an outdated one: untouched segments keep the fingerprint they had.
- The translator updates the text, or confirms it with **Still correct** (saved with the next save), or marks the whole locale **up to date**, which records the current source for every translated text.
- The published translation and the draft each have their own fingerprints (a draft's travel with it, Translations' `getTranslationDraftSource()`), so updating a text in a draft doesn't hide that readers still see the outdated one. The editor flags the texts in its fields (the draft's); statuses and the table, the published ones.
- Texts translated before fingerprints existed have none and are never claimed outdated. A translation written outside the editor (an import, code) loses the fingerprints of the one it replaced, as Translations forgets the source of a translation changed without one. **Mark as up to date** starts tracking them, for the published translation and the draft.
- A source text that changes while someone is translating it is a save conflict (see [Concurrent editing](#concurrent-editing)).

### In the editor

The locale navigation shows each locale's published progress ("4/6"), an outdated clock and validation errors. The panel header shows the published progress ("4 of 6 published"), the drafts' ("Draft: 5 of 6") and whether the locale is outdated or inactive. Each field with a draft says where the draft stands and what readers see meanwhile. Under every segment, whether it is translated, and a **Copy** button for the reference text.

### In the table

`TranslationsColumn` shows a compact badge per locale: green when complete, amber when incomplete or outdated, gray when missing, with a clock when outdated, a pencil when there are drafts, and a tooltip. A badge opens the editor at its locale through the table's `TranslateAction` (by default `translate`; `->translateAction('name')` for another). With more than six locales the column shows one summary badge instead (`->limit(10)` to change it).

The column eager loads `translations` (and `translationStates`), so it costs no queries per row on any storage.

### Filtering

`TranslationStatusFilter` filters by a locale's status, exactly as the column and the editor report it: not translated, incomplete, complete and, with translation states, outdated (marked, or with outdated published texts) and has drafts.

Statuses count segments, which only the translation form's schema can tell, so the filter decides them in PHP:

1. An SQL query narrows the records down to those that can match, with the Translations query scopes (every storage): the source has a value; for **complete**, every attribute the source has is published; for **incomplete**, something is published; for **outdated**, the locale has a state that is marked or has fingerprints. **Has drafts** is decided in SQL alone.
2. The candidates are read in chunks of 200 with their translations and states eager loaded, so the number of queries doesn't grow with the rows, and each one's status is computed as the column does.
3. The table is limited to the matches (`whereKey()`), keeping its other filters, search, sorting and pagination.

The cost grows with the number of candidates: fine for the thousands of records an editorial table holds, not for millions. A source locale per record (`sourceLocale(fn ($record) => …)`) skips the source conditions of step 1.

### Translation states

With `HasTranslationStates` on the model, the editor's locale menu offers:

- **Mark as outdated** (a manual flag, e.g. after a change of meaning that isn't in the text) and **Mark as up to date** (clears the flag and confirms every translated text against the current source);
- **Change status**, when `translations.states.status_enum` is configured.

Publishing a change to a locale clears its manual flag, as Translations does on every save; saving a draft doesn't. The table filter's **Outdated** finds both manually flagged locales and published texts whose source changed.

## Saving

The editing contract, the same in every presentation and layout:

1. **Exact values.** Segments show exactly what is stored for their locale, never fallback text.
2. **Locales load when opened.** Switching locales loads the new one and keeps unsaved texts in the others.
3. **One save for every changed locale.** **Save** checks and writes every opened locale with changed segments, in one locked transaction. Locales only looked at are neither checked nor saved. The source, other locales and attributes outside the form are never written.
4. **Structure from the source.** Each changed attribute is rebuilt from the source's current structure with the translated texts, and checked.
5. **Published when complete and current.** A field with every text translated, and no outdated text readers don't see already, is published and its draft forgotten; otherwise it is saved as a draft (see [Drafts and publishing](#drafts-and-publishing)). The editor never stores empty strings, gaps or the source's text in a published value.
6. **Problems keep your input.** A refused text is marked at its segment with the reason; the navigation marks locales with errors.

On the page, leaving with unsaved changes asks for confirmation (with the panel's `unsavedChangesAlerts()`); switching locales or the reference isn't a change.

**Delete translation** in a locale's menu removes every value of that locale, after confirmation.

## Concurrent editing

Saving reads what is stored **now** and decides there, inside `lockTranslationsForUpdate()` of Translations: the record's row is locked and its translations are read again with locking reads, so no other save can change them between the check and the write, in any storage. Nothing is decided from the copy the editor loaded.

| Two editors change… | Result |
|---|---|
| different locales | both kept |
| different fields of a locale | both kept |
| different paragraphs, list items or repeater items of a field | both kept (merged segment by segment, drafts included) |
| a translation while the author reorders, adds or removes items with ids | kept, at its item |
| the same segment, differently | the second save is refused |
| a translation while the author changed that segment's source text | refused |
| a field while the author changed the structure of the document it is in (added a paragraph), or a fixed list, or a block's type | refused for that field |
| anything, on a record deleted meanwhile | refused: "this record was deleted" |
| translation states (outdated, status) | kept; saving a locale marks it up to date |

A refused save writes nothing, not even its other changes, and the transaction rolls back on any failure. The editor then:

- keeps every text the translator typed, and loads what is stored now around them;
- marks each conflicting segment: the other text, with **Use their text**, or "the source text changed";
- lists texts whose field's source structure changed, to copy into the new segments;
- names each conflict in a notification ("Georgian › Description: someone else saved it").

Saving again, after reviewing, saves the translator's texts: nothing is ever overwritten without a conflict having been shown first. The editor's loaded state is checked only for the translator's own conflicts; authorization and the written segments always come from the server.

On SQLite, which has no row locks, writers are serialized by the database lock. On MariaDB with snapshot isolation, a save that started reading before another committed fails with a deadlock error instead of reading stale data.

## Validation

Changed segments are checked on save: tags, placeholders, line breaks in single-line fields and the declared length limits, per locale (the form receives the locale). Unchanged texts are never checked, so stored text never blocks a draft. Field rules other than length (`required()`, `rules()`) don't apply to translations: completeness replaces `required()`.

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

JSON, table and shared storage behave identically: the editor reads with `getTranslation()` and `getTranslatedLocales()`, writes with `setTranslation()` inside `lockTranslationsForUpdate()`, deletes with `forgetTranslations()`, and queries with `whereHasTranslation()` and `whereDoesntHaveTranslation()`. Nothing in this package depends on where translations are stored, and models with different storages work side by side. Structured attributes (repeaters, builders, TipTap JSON) need array values: JSON or shared storage, or an `array` cast on the translation model.

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

The stylesheet uses Filament's color variables and container queries: the comparison layout switches between side by side and stacked on the editor's own width, so pages, modals and slide-overs behave the same. Tags are shown left to right inside right-to-left text.

## Development

```bash
composer check                                       # Pint + unit and feature tests
composer test:pgsql                                  # also test:mysql, test:mariadb (servers: laravel-tipi/translations compose.yaml)
npm install && npx playwright install chromium       # once, for browser tests
composer test:browser                                # browser tests (Playwright)
vendor/bin/testbench workbench:build && vendor/bin/testbench serve
```

The workbench serves the test fixtures as an app: sign in at `/admin/login` as `editor@example.com` / `password`.

## License

MIT.
