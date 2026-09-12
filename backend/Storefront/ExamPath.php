<?php

namespace Theme\Backend\Storefront;

use App\Contracts\Storefront\PathResolver;
use App\Contracts\Storefront\ResolvedPath;
use App\Models\Product;
use Theme\Backend\Models\Exam;

/**
 * `/exam/{slug}` — the exam's own page, served through core's `storefront.paths` seam.
 *
 * Declared in `manifest.json` under `storefront.paths` with prefix `exam`. Core asks this
 * class what lives at the slug after the Page lookup and before its own prefixes, renders the
 * answer with the theme's `pages/exam.blade.php`, and guards it as customer-only — a stranger
 * is sent to `/login?redirect=…` before anything about the exam is rendered.
 *
 * **The record is the product**, because the product *is* the exam (see the README). It has
 * to be on sale and have its Exam tab switched on: a product whose `is_exam` was switched off
 * is not an exam to a visitor, whatever it was last month. Nothing about who is asking is
 * decided here — the Exam Papers block on the page answers "does this candidate hold it", and
 * answers a stranger and a non-existent slug identically so neither learns which exams exist.
 *
 * The slug is matched in the request's locale and then the fallback, exactly as core matches
 * a product under `products/`.
 */
class ExamPath implements PathResolver
{
    public function resolve(string $slug, string $locale): ?ResolvedPath
    {
        $fallback = (string) config('app.fallback_locale', 'en');

        $product = Product::query()
            ->where('status', 'active')
            ->where(function ($q) use ($locale, $fallback, $slug) {
                $q->whereJsonContains("slug->{$locale}", $slug)
                    ->orWhereJsonContains("slug->{$fallback}", $slug);
            })
            ->whereIn('id', Exam::query()->isExam()->select('product_id'))
            ->first();

        if ($product === null) {
            return null;
        }

        return new ResolvedPath(record: $product, template: 'exam', customerOnly: true);
    }
}
