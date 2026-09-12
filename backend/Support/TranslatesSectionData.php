<?php

namespace Theme\Backend\Support;

/**
 * Resolve a section field the page builder stored per locale.
 *
 * A `translatable` section field is saved as `{"en": "…", "ms": "…"}`, not as a string — the
 * same shape core's own renderer unpacks in `MetaRepository::resolveTranslatable()` and every
 * Saffron driver unpacks by hand. A driver that hands the raw value to Blade puts an array
 * through `{{ }}`, and `htmlspecialchars()` refuses it with a 500 on the storefront the moment
 * an operator types a heading. Shared here rather than copied into each driver, because three
 * copies of one rule is how one of them ends up different.
 */
trait TranslatesSectionData
{
    /**
     * The value for $locale, falling back to English, then to whatever locale was saved first.
     * A plain string is returned as it is; anything empty is ''.
     */
    protected function translate(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? $value['en'] ?? (count($value) ? reset($value) : '');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
