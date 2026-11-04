<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use RuntimeException;

/**
 * Source content the text-only editor can't translate safely: the
 * attribute is shown as not translatable, with the reason, instead of
 * being translated wrongly or partly.
 *
 * @internal
 */
final class UnsupportedContent extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $replace = [],
    ) {
        parent::__construct(__('tipi-filament-translations::editor.unsupported.'.$reason, $replace));
    }
}
