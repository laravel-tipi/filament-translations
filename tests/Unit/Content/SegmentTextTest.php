<?php

declare(strict_types=1);

use Tipi\Translations\Filament\Content\InvalidTranslation;
use Tipi\Translations\Filament\Content\Segment;
use Tipi\Translations\Filament\Content\SegmentText;
use Tipi\Translations\Filament\Content\TagInfo;

function reason(Closure $callback): ?string
{
    try {
        $callback();

        return null;
    } catch (InvalidTranslation $exception) {
        return $exception->reason;
    }
}

it('treats blank texts as not translated', function (): void {
    $segment = new Segment('name', '', [], 'text', 'Saperavi');

    expect(SegmentText::read($segment, null))->toBeNull()
        ->and(SegmentText::read($segment, "  \n "))->toBeNull()
        ->and(SegmentText::read($segment, ' საფერავი '))->toBe('საფერავი');
});

it('keeps placeholders, as often as the source has them', function (): void {
    $segment = new Segment('notice', '', [], 'text', 'Hello :name, you have {count} new {{ kind }} messages (%s).');

    expect(SegmentText::read($segment, 'გამარჯობა :name, {count} ახალი {{kind}} შეტყობინება (%s).'))->not->toBeNull()
        ->and(reason(fn () => SegmentText::read($segment, 'გამარჯობა, {count} {{ kind }} (%s).')))->toBe('placeholder_missing')
        ->and(reason(fn () => SegmentText::read($segment, ':name :user {count} {{ kind }} %s')))->toBe('placeholder_unexpected');

    // Colons in prose and URLs are not placeholders.
    $prose = new Segment('note', '', [], 'text', 'Note: visit https://example.com at 10:30.');
    expect(SegmentText::read($prose, 'შენიშვნა: ეწვიეთ https://example.com 10:30-ზე.'))->not->toBeNull();
});

it('checks placeholders in rich text without its tags', function (): void {
    $segment = new Segment('body', '0', [], 'paragraph', 'Dear <b1>:name</b1>', ['b1' => new TagInfo('b', false, 'b', 'Bold')], rich: true);

    expect(SegmentText::read($segment, 'ძვირფასო <b1>:name</b1>'))->toBe('ძვირფასო <b1>:name</b1>')
        ->and(reason(fn () => SegmentText::read($segment, 'ძვირფასო <b1>მეგობარო</b1>')))->toBe('placeholder_missing');
});

it('applies single-line and length limits', function (): void {
    $segment = new Segment('name', '', [], 'text', 'Saperavi', multiline: false, length: ['min' => 2, 'max' => 10]);

    expect(reason(fn () => SegmentText::read($segment, "Sape\nravi")))->toBe('single_line')
        ->and(reason(fn () => SegmentText::read($segment, 'საფერავი საუკეთესო')))->toBe('too_long')
        ->and(reason(fn () => SegmentText::read($segment, 'ს')))->toBe('too_short')
        ->and(SegmentText::read($segment, 'საფერავი'))->toBe('საფერავი');
});
