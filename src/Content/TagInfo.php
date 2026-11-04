<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

/**
 * What a tag of a run stands for.
 *
 * @internal
 */
final readonly class TagInfo
{
    /**
     * @param  string  $kind  b, i, u, s, a, sub, sup, mark, span, code, img, …
     * @param  string  $signature  identifies the tag across documents: kind and target
     * @param  string  $description  for translators: "Bold", "Link: https://…"
     */
    public function __construct(
        public string $kind,
        public bool $void,
        public string $signature,
        public string $description,
    ) {}

    public static function describe(string $kind, string $detail = ''): string
    {
        $key = 'tipi-filament-translations::editor.tags.'.$kind;
        $label = trans()->has($key) ? __($key) : __('tipi-filament-translations::editor.tags.other');

        $detail = trim((string) preg_replace('/\s+/', ' ', $detail));

        return $detail === '' ? $label : $label.': '.mb_strimwidth($detail, 0, 80, '…');
    }
}
