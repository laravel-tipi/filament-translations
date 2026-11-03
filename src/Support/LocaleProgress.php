<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Tipi\Support\Locale;
use Tipi\Translations\Filament\Enums\TranslationStatus;

/**
 * The translation progress of one locale of a record: how many of the
 * source's segments it translates.
 */
final readonly class LocaleProgress
{
    /**
     * @param  list<string>  $review  attributes with stored translations that no longer line up with the source
     */
    public function __construct(
        public Locale $locale,
        public int $translated,
        public int $total,
        public bool $hasValues = false,
        public array $review = [],
        public ?CarbonInterface $outdatedSince = null,
        public BackedEnum|string|null $workflowStatus = null,
    ) {}

    public function status(): TranslationStatus
    {
        return match (true) {
            $this->total === 0 => TranslationStatus::NotApplicable,
            $this->translated === 0 => TranslationStatus::Missing,
            $this->translated >= $this->total => TranslationStatus::Complete,
            default => TranslationStatus::Incomplete,
        };
    }

    public function isOutdated(): bool
    {
        return $this->outdatedSince !== null;
    }

    public function needsWork(): bool
    {
        return $this->missing() > 0 || $this->isOutdated() || $this->review !== [];
    }

    public function missing(): int
    {
        return max(0, $this->total - $this->translated);
    }

    /**
     * Whether anything is stored for the locale (delete, outdated actions).
     */
    public function hasContent(): bool
    {
        return $this->hasValues;
    }

    public function color(): string
    {
        return $this->isOutdated() || $this->review !== [] ? 'warning' : $this->status()->getColor();
    }

    public function fraction(): string
    {
        return $this->total === 0 ? '–' : "{$this->translated}/{$this->total}";
    }

    public function summary(): string
    {
        $summary = $this->total === 0
            ? __('tipi-filament-translations::editor.progress.not_applicable', ['locale' => $this->locale->name])
            : __('tipi-filament-translations::editor.progress.summary', [
                'locale' => $this->locale->name,
                'translated' => $this->translated,
                'total' => $this->total,
            ]);

        if ($this->isOutdated()) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.outdated');
        }

        if ($this->review !== []) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.review');
        }

        if ($this->locale->isInactive()) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.inactive');
        }

        return $summary;
    }
}
