<?php

namespace Theme\Backend\Support;

/**
 * Hardening every admin list needs, written once instead of three times.
 *
 * **DataTables does not send what a form would.** The admin list posts `search` as an OBJECT —
 * `{value, regex}` — not a string, so casting it with `(string)` throws "Array to string
 * conversion" and 500s the whole screen. A multi-select filter arrives as an array for exactly
 * the same reason. Saffron met this on its Outlets list and hardened it inline; three modules
 * in one theme is where that stops being worth repeating.
 *
 * Both helpers answer `null` for "the operator did not filter by this", so a caller can write
 * a plain `if` and never has to distinguish absent from empty from malformed.
 */
trait ResolvesListFilters
{
    /**
     * A filter value as a non-empty scalar string, or null.
     *
     * Anything that is not a scalar — an array from a multi-select, an object from a crafted
     * request — is treated as absent rather than coerced, because there is no honest way to
     * turn a list into the single value a `where` clause wants.
     */
    protected function scalarFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The term the operator typed, unwrapped from whichever shape it arrived in.
     */
    protected function searchTerm(array $filters): ?string
    {
        $search = $filters['search'] ?? null;

        // DataTables' shape, unwrapped rather than rejected: the value is what was typed.
        if (is_array($search)) {
            $search = $search['value'] ?? null;
        }

        if (! is_scalar($search)) {
            return null;
        }

        $search = trim((string) $search);

        return $search === '' ? null : $search;
    }

    /** An id filter that must be a positive integer to mean anything. */
    protected function idFilter(array $filters, string $key): ?int
    {
        $value = $this->scalarFilter($filters, $key);

        if ($value === null || ! ctype_digit(ltrim($value, '-'))) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }
}
