<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Enums\TranslationStatus;

/**
 * How far one locale of a record is translated, counting the attributes
 * the translation form edits, and its editorial state when the model uses
 * translation states.
 */
final readonly class LocaleProgress
{
    public function __construct(
        public Locale $locale,
        public int $translated,
        public int $total,
        public ?CarbonInterface $outdatedSince = null,
        public BackedEnum|string|null $workflowStatus = null,
    ) {}

    public function status(): TranslationStatus
    {
        return match (true) {
            $this->translated === 0 => TranslationStatus::Missing,
            $this->translated >= $this->total => TranslationStatus::Complete,
            default => TranslationStatus::Partial,
        };
    }

    public function isOutdated(): bool
    {
        return $this->outdatedSince !== null;
    }

    public function missing(): int
    {
        return max(0, $this->total - $this->translated);
    }

    public function hasContent(): bool
    {
        return $this->translated > 0;
    }

    /**
     * The color for badges: outdated wins over completeness.
     */
    public function color(): string
    {
        return $this->isOutdated() ? 'warning' : $this->status()->getColor();
    }

    /**
     * "4/6", the compact form used in badges.
     */
    public function fraction(): string
    {
        return "{$this->translated}/{$this->total}";
    }

    /**
     * A sentence for tooltips and screen readers.
     */
    public function summary(): string
    {
        $summary = __('tipi-filament-translations::editor.progress.summary', [
            'locale' => $this->locale->name,
            'translated' => $this->translated,
            'total' => $this->total,
        ]);

        if ($this->isOutdated()) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.outdated');
        }

        if ($this->locale->isInactive()) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.inactive');
        }

        return $summary;
    }
}
