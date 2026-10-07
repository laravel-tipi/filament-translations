<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Schemas\Components\Concerns;

use Closure;

trait HasTranslationValidation
{
    protected array|Closure|null $createRules = null;

    protected array|Closure|null $updateRules = null;

    public function createRules(array|Closure $rules): static
    {
        $this->createRules = $rules;

        return $this;
    }

    public function updateRules(array|Closure $rules): static
    {
        $this->updateRules = $rules;

        return $this;
    }

    public function getCreateRules(): array
    {
        return $this->createRules !== null
            ? $this->evaluate($this->createRules)
            : $this->getValidationRules();
    }

    public function getUpdateRules(): array
    {
        return $this->updateRules !== null
            ? $this->evaluate($this->updateRules)
            : $this->getValidationRules();
    }
}
