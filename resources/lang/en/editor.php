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

    'source' => [
        'label' => 'Translate from',
    ],

    'navigation' => [
        'label' => 'Languages',
        'select_label' => 'Language',
        'errors' => 'Errors',
    ],

    'empty' => 'There is no other language to translate into.',

    'panel' => [
        'source' => 'Source',
        'target' => 'Translation',
        'progress' => ':translated of :total translated',
        'outdated' => 'Outdated',
        'outdated_notice' => 'Marked as outdated on :date: the source may have changed since. Review it, then save or mark it as up to date.',
        'inactive' => 'Inactive',
    ],

    'row' => [
        'translated' => 'Translated',
        'fallback' => 'Not translated · readers see :locale',
        'missing' => 'Not translated',
        'copy_source' => 'Copy source',
        'copy_source_tooltip' => 'Replace this field with the :locale text',
    ],

    'status' => [
        'complete' => 'Complete',
        'partial' => 'Incomplete',
        'missing' => 'Not translated',
    ],

    'progress' => [
        'summary' => ':locale: :translated of :total translated.',
        'outdated' => 'Outdated.',
        'inactive' => 'Inactive language.',
    ],

    'actions' => [
        'label' => ':locale actions',
        'mark_current' => 'Mark as up to date',
        'mark_outdated' => 'Mark as outdated',
        'mark_others_outdated' => 'Mark other languages as outdated',
        'mark_others_outdated_description' => 'Use this after changing the :locale text, so that the other translations are reviewed.',
        'set_status' => 'Change status',
        'status' => 'Status',
        'delete' => 'Delete translation',
        'delete_heading' => 'Delete the :locale translation?',
        'delete_description' => 'Every :locale value of :record will be removed. Readers will see the fallback language instead.',
    ],

    'notifications' => [
        'saved' => 'Translations saved',
        'unchanged' => 'No changes to save',
        'conflict_title' => 'Not saved: the translation changed meanwhile',
        'conflict_body' => 'Someone else saved :locales after you opened the editor. Your changes are still in the form. Reload to see the other changes, then apply yours again.',
        'marked_current' => ':locale marked as up to date',
        'marked_outdated' => ':locale marked as outdated',
        'marked_others_outdated' => 'Other languages marked as outdated',
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
        'indicator' => ':locale: :status',
    ],
];
