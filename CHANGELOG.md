# Changelog

## 0.2.0

A new package, built on `laravel-tipi/translations` 0.2 and `laravel-tipi/localization` 0.2:

- `TranslationEditor`: page, modal or slide-over presentation; comparison or locale-focused layout; tabs or select navigation; content context, locales and inactive locales. Panel defaults through `FilamentTranslationsPlugin`, resource overrides through the `Translatable` concern, action overrides through `TranslateAction::editor()`.
- Text-only translation: `translationForm()` declares the content; translators edit its text segments in textareas. Plain text, Markdown, rich text (HTML and TipTap JSON), repeaters and builders, with inline formatting as movable numbered tags, placeholder checks (`placeholders()`) and item identity by id (`itemKey()`). Every rebuilt value is read back and checked.
- A canonical source locale (`sourceLocale()`, the global default by default) decides what is translated; another language can be shown beside the target as reference.
- Partial translations of fields with several texts are saved as unpublished drafts (Translations' drafts) and published once complete, by a translator's save, and only if that shows readers no outdated text they don't see already: readers never see gaps, source text or newly outdated text. A draft the source completed is published on request ("Publish when saving"). The editor shows each draft apart from what readers see.
- Freshness per segment: source fingerprints recorded when texts are saved, separately for the published translation and the draft; a text is outdated when its source text changed since, until updated, confirmed ("Still correct") or the locale is marked up to date.
- Repeaters and builders that can change must declare an item id (`ItemId`); missing or duplicate ids make the field untranslatable with a reason. Only fixed lists match by position.
- `title` and `alt` texts, Markdown HTML blocks and declared custom block texts (`customBlocks()`) are translated; content that can't be translated safely is shown with its reason and counts as incomplete.
- Completeness measured against the source, segment by segment, derived when read, of what readers see: Complete, Incomplete, Not translated, Nothing to translate, plus Outdated and Has drafts. Drafts always save.
- Saves merge with concurrent changes to other locales, fields, segments and items inside `lockTranslationsForUpdate()`; overlapping changes, changed source texts and changed source structures are conflicts that save nothing, keep the translator's input and show what changed.
- `TranslateAction` and the `TranslateRecord` page.
- `TranslationsColumn` and `TranslationStatusFilter`, which filters by exactly the column's statuses: narrowed in SQL, decided per candidate in chunks.
- Locale-level authorization and translation state actions.
- Requires `laravel-tipi/translations` with `lockTranslationsForUpdate()`, drafts with their own sources, `ext-dom` and `league/commonmark`.
