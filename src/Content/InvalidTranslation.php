<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use RuntimeException;

/**
 * A translated text that can't be stored: tags or placeholders don't match
 * the source, or the format can't express it. The message is for the
 * translator.
 *
 * @internal
 */
final class InvalidTranslation extends RuntimeException
{
    /**
     * Set by the editor: the locale and segment key the text belongs to.
     */
    public ?string $locale = null;

    public ?string $key = null;

    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $replace = [],
        public ?string $path = null,
    ) {
        parent::__construct(__('tipi-filament-translations::editor.invalid.'.$reason, $replace));
    }

    public function at(string $path): self
    {
        $this->path ??= $path;

        return $this;
    }
}
