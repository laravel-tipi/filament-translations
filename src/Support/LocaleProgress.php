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
     * @param  int  $stale  translated segments whose source text changed since
     * @param  list<string>  $drafts  attributes with unpublished drafts
     * @param  list<string>  $unsupported  attributes whose source can't be translated as text
     */
    public function __construct(
        public Locale $locale,
        public int $translated,
        public int $total,
        public bool $hasValues = false,
        public array $review = [],
        public int $stale = 0,
        public array $drafts = [],
        public array $unsupported = [],
        public ?CarbonInterface $outdatedSince = null,
        public BackedEnum|string|null $workflowStatus = null,
    ) {}

    public function status(): TranslationStatus
    {
        return match (true) {
            $this->total === 0 && $this->unsupported === [] => TranslationStatus::NotApplicable,
            $this->translated === 0 => TranslationStatus::Missing,
            $this->translated >= $this->total && $this->unsupported === [] => TranslationStatus::Complete,
            default => TranslationStatus::Incomplete,
        };
    }

    /**
     * Marked outdated, or translated from source texts that changed since.
     */
    public function isOutdated(): bool
    {
        return $this->outdatedSince !== null || $this->stale > 0;
    }

    public function isMarkedOutdated(): bool
    {
        return $this->outdatedSince !== null;
    }

    public function hasDrafts(): bool
    {
        return $this->drafts !== [];
    }

    public function needsWork(): bool
    {
        return $this->missing() > 0 || $this->isOutdated() || $this->review !== [] || $this->drafts !== [] || $this->unsupported !== [];
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
        return $this->hasValues || $this->drafts !== [];
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

        if ($this->stale > 0) {
            $summary .= ' '.trans_choice('tipi-filament-translations::editor.progress.stale', $this->stale, ['count' => $this->stale]);
        } elseif ($this->isOutdated()) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.outdated');
        }

        if ($this->drafts !== []) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.drafts');
        }

        if ($this->unsupported !== []) {
            $summary .= ' '.__('tipi-filament-translations::editor.progress.unsupported');
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
