<?php

declare(strict_types=1);

namespace Tipi\Translations\Filament\Support;

use Closure;
use Tipi\Localization\LocaleContext;
use Tipi\Localization\LocaleContexts;

/**
 * The locale context content is published in, which is not the context of
 * the panel the editor runs in.
 *
 * @internal
 */
final readonly class ContentContext
{
    public function __construct(
        private LocaleContexts $contexts,
    ) {}

    public function get(?string $name): LocaleContext
    {
        return $name === null ? $this->contexts->default() : $this->contexts->get($name);
    }

    /**
     * Run the callback with the context current, so fallback resolves as
     * readers of that context see it.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(LocaleContext $context, Closure $callback): mixed
    {
        $previous = $this->contexts->current()->name;

        if ($previous === $context->name) {
            return $callback();
        }

        $this->contexts->setCurrent($context->name);

        try {
            return $callback();
        } finally {
            $this->contexts->setCurrent($previous);
        }
    }
}
