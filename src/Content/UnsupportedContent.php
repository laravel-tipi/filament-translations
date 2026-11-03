<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Content;

use RuntimeException;

/**
 * Source content the text-only editor can't translate safely. The
 * attribute is shown as not translatable instead of being corrupted.
 *
 * @internal
 */
final class UnsupportedContent extends RuntimeException {}
