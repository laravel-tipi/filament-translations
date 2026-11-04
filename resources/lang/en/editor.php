<?php

declare(strict_types=1);

return [
    'action' => [
        'label' => 'Translate',
        'heading' => 'Translate :record',
        'save' => 'Save translations',
    ],

    'page' => [
        'title' => 'Translate :record',
        'breadcrumb' => 'Translations',
        'back' => 'Back',
    ],

    'reference' => [
        'label' => 'Show beside',
        'source' => ':locale (source)',
    ],

    'navigation' => [
        'label' => 'Languages',
        'select_label' => 'Language',
        'errors' => 'Errors',
    ],

    'empty' => 'There is no language to translate into.',

    'panel' => [
        'source' => 'Source',
        'reference' => 'Reference',
        'target' => 'Translation',
        'progress' => ':translated of :total published',
        'drafted' => 'Draft: :drafted of :total',
        'outdated' => 'Outdated',
        'outdated_notice' => 'Marked as outdated on :date: the source may have changed since. Review it, then save or mark it as up to date.',
        'inactive' => 'Inactive',
        'nothing' => 'The :locale source has no text to translate yet.',
    ],

    'attribute' => [
        'fallback' => 'Not translated · readers see :locale',
        'empty' => 'Nothing to translate: the :locale source is empty.',
        'unsupported' => 'This content can’t be translated as text here. Edit it in the content form.',
        'orphans' => '{1} 1 stored text no longer matches the source|[2,*] :count stored texts no longer match the source',
        'unsaved' => 'The source changed while you were translating, so these texts weren’t saved. Copy what you need into the new fields:',
        'draft' => 'Unpublished draft.',
        'draft_incomplete' => 'It is published once every text is translated.',
        'draft_outdated' => '{1} Every text is translated, but 1 was translated from a source text that changed since: update it or confirm it is still correct, then save to publish.|[2,*] Every text is translated, but :count were translated from source texts that changed since: update them or confirm they are still correct, then save to publish.',
        'draft_ready' => 'The source changed, and every text is translated now: it can be published as it is.',
        'publish' => 'Publish when saving',
        'publish_requested' => 'Will be published when you save.',
        'readers_published' => 'Readers see the published translation meanwhile (:translated of :total texts).',
        'readers_fallback' => 'Readers see :locale meanwhile.',
        'readers_nothing' => 'Readers see nothing meanwhile.',
        'stale' => '{1} 1 text was translated from a source text that changed since.|[2,*] :count texts were translated from source texts that changed since.',
    ],

    'unsupported' => [
        'item_id_missing' => 'Item :position has no [:key]: items are matched by id, so translations can’t be kept with their items. Save the record in its form to give every item an id.',
        'item_id_duplicate' => 'Two items have the [:key] “:id”: items are matched by id, so translations can’t be kept with their items. Give the copy a new id in the record’s form.',
        'custom_block' => 'The custom block “:block” has text the translation editor doesn’t know about. Declare its text fields with customBlocks().',
        'markdown' => 'The Markdown contains a :construct, which can’t be translated as text.',
        'markdown_html' => 'The Markdown contains HTML that can’t be rewritten safely: :html',
        'markdown_inline_html' => 'The Markdown contains inline HTML with text in its attributes: :html',
        'json' => 'The rich text is not valid TipTap JSON.',
    ],

    'roles' => [
        'text' => 'Text',
        'paragraph' => 'Paragraph',
        'heading' => 'Heading',
        'list_item' => 'List item',
        'quote' => 'Quote',
        'table_cell' => 'Table cell',
        'caption' => 'Caption',
        'alt' => 'Image description',
        'title' => 'Tooltip',
        'block_field' => 'Block text',
    ],

    'row' => [
        'translated' => 'Translated',
        'missing' => 'Not translated',
        'copy' => 'Copy :locale',
        'copy_tooltip' => 'Replace the translation with the :locale text, tags included',
        'no_reference' => 'No :locale text',
        'conflict_changed' => 'Someone else saved this text while you were editing:',
        'conflict_removed' => 'They removed the text.',
        'conflict_source' => 'The source text changed while you were editing. Check your translation against it.',
        'use_theirs' => 'Use their text',
        'outdated' => 'Outdated',
        'stale' => 'The source text changed since this was translated. Update the translation, or confirm it is still correct.',
        'still_correct' => 'Still correct',
        'reviewed' => 'Confirmed as still correct: saved with the next save.',
    ],

    'status' => [
        'complete' => 'Complete',
        'incomplete' => 'Incomplete',
        'missing' => 'Not translated',
        'not_applicable' => 'Nothing to translate',
    ],

    'progress' => [
        'summary' => ':locale: :translated of :total texts published.',
        'not_applicable' => ':locale: nothing to translate.',
        'outdated' => 'Outdated.',
        'review' => 'Some texts no longer match the source.',
        'stale' => '{1} 1 text is outdated.|[2,*] :count texts are outdated.',
        'drafts' => 'Unpublished drafts: :drafted of :total texts translated.',
        'unsupported' => 'Some content can’t be translated here.',
        'inactive' => 'Inactive language.',
    ],

    'actions' => [
        'label' => ':locale actions',
        'mark_current' => 'Mark as up to date',
        'mark_outdated' => 'Mark as outdated',
        'set_status' => 'Change status',
        'status' => 'Status',
        'delete' => 'Delete translation',
        'delete_heading' => 'Delete the :locale translation?',
        'delete_description' => 'Every :locale value of :record will be removed. Readers will see the fallback language instead.',
    ],

    'notifications' => [
        'saved' => 'Translations saved',
        'unchanged' => 'No changes to save',
        'conflict_title' => 'Not saved: the content changed meanwhile',
        'conflict_body' => 'Your texts are still in the form, with what changed marked. Review them, then save again. :conflicts',
        'conflict_changed' => ':locale › :field: someone else saved it',
        'conflict_source' => ':locale › :field: its source text changed',
        'conflict_structure' => ':locale › :field: its source structure changed',
        'deleted_record' => 'Not saved: this record was deleted',
        'marked_current' => ':locale marked as up to date',
        'marked_outdated' => ':locale marked as outdated',
        'status_updated' => ':locale status updated',
        'deleted' => ':locale translation deleted',
    ],

    'column' => [
        'label' => 'Translations',
        'summary' => ':complete of :total complete',
    ],

    'filter' => [
        'label' => 'Translation',
        'locale' => 'Language',
        'status' => 'Status',
        'missing' => 'Not translated',
        'incomplete' => 'Incomplete',
        'complete' => 'Complete',
        'outdated' => 'Outdated',
        'drafts' => 'Has unpublished drafts',
        'indicator' => ':locale: :status',
    ],

    'invalid' => [
        'unknown_tag' => ':tag is not a tag of the source. Keep the source’s tags as they are.',
        'tag_form' => 'Write :tag as :expected.',
        'tag_order' => ':tag closes a tag that isn’t open. Close tags in the reverse order you open them.',
        'tag_empty' => ':tag must surround some text.',
        'tag_twice' => ':tag is used more than once.',
        'tag_unclosed' => ':tag is not closed.',
        'tag_missing' => 'Keep the source’s tags: :tags. You can move them.',
        'placeholder_missing' => 'Keep the placeholders :placeholders: they are replaced when shown.',
        'placeholder_unexpected' => 'The source has no placeholder :placeholders.',
        'single_line' => 'This text can’t have line breaks.',
        'too_long' => 'This text can have at most :max characters.',
        'too_short' => 'This text needs at least :min characters.',
        'format' => 'This text can’t be stored with its formatting. Check the tags, or move them away from punctuation.',
    ],

    'tags' => [
        'b' => 'Bold',
        'i' => 'Italic',
        'u' => 'Underline',
        's' => 'Strikethrough',
        'a' => 'Link',
        'url' => 'Link',
        'sub' => 'Subscript',
        'sup' => 'Superscript',
        'mark' => 'Highlight',
        'span' => 'Formatting',
        'code' => 'Code',
        'img' => 'Image',
        'media' => 'Embedded media',
        'input' => 'Form control',
        'html' => 'HTML',
        'variable' => 'Placeholder',
        'other' => 'Content',
    ],
];
