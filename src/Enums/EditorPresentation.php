<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Enums;

/**
 * Where the translation editor opens.
 */
enum EditorPresentation: string
{
    /** A resource page with its own URL: deep links, reloads and browser history. */
    case Page = 'page';

    /** A modal over the current page. */
    case Modal = 'modal';

    /** A modal that slides over from the side of the screen. */
    case SlideOver = 'slide-over';

    public function opensModal(): bool
    {
        return $this !== self::Page;
    }
}
