<?php

namespace Theme\Backend\Storefront;

use App\Contracts\Storefront\PathResolver;
use App\Contracts\Storefront\ResolvedPath;
use App\Models\Product;
use Theme\Backend\Models\Exam;
use Theme\Backend\Models\ExamPaper;

/**
 * `/exam/{slug}` and `/exam/{slug}/{paper}` — the exam's own pages, served through core's
 * `storefront.paths` seam.
 *
 * Declared in `manifest.json` under `storefront.paths` with prefix `exam`. Core asks this
 * class what lives at everything after the prefix, renders the answer with the theme's own
 * template, and guards it as customer-only — a stranger is sent to `/login?redirect=…` before
 * anything about the exam is rendered.
 *
 * Two addresses, one resolver, because the seam hands over the whole remainder of the path:
 *
 * - `{slug}` — **the record is the product**, because the product *is* the exam (see the
 *   README). Template `exam`, which draws the operator's `exam` Page as its layout.
 * - `{slug}/{paper}` — the record is the paper, template `exam-paper`: the player. The paper
 *   must belong to that exam and be active; an inactive paper is not an address to a visitor.
 *
 * The product has to be on sale and have its Exam tab switched on: a product whose `is_exam`
 * was switched off is not an exam to a visitor, whatever it was last month. Nothing about who
 * is asking is decided here — the Exam Papers block and the player's actions answer "does this
 * candidate hold it", and answer a stranger and a non-existent slug identically so neither
 * learns which exams exist.
 *
 * Slugs are matched in the request's locale and then the fallback, exactly as core matches a
 * product under `products/`.
 */
class ExamPath implements PathResolver
{
    public function resolve(string $slug, string $locale): ?ResolvedPath
    {
        [$examSlug, $paperSlug] = array_pad(explode('/', $slug, 2), 2, null);

        if ($examSlug === '' || $examSlug === null) {
            return null;
        }

        $fallback = (string) config('app.fallback_locale', 'en');

        $product = Product::query()
            ->where('status', 'active')
            ->where(function ($q) use ($locale, $fallback, $examSlug) {
                $q->whereJsonContains("slug->{$locale}", $examSlug)
                    ->orWhereJsonContains("slug->{$fallback}", $examSlug);
            })
            ->whereIn('id', Exam::query()->isExam()->select('product_id'))
            ->first();

        if ($product === null) {
            return null;
        }

        if ($paperSlug === null) {
            return new ResolvedPath(record: $product, template: 'exam', customerOnly: true);
        }

        $paper = ExamPaper::query()
            ->active()
            ->where('product_id', $product->id)
            ->where('slug', $paperSlug)
            ->first();

        if ($paper === null) {
            return null;
        }

        $paper->setRelation('product', $product);

        $title = $paper->getTranslation('title', $locale, true) ?: $paper->slug;
        $exam  = $product->getTranslation('title', $locale, true) ?: $examSlug;

        return new ResolvedPath(
            record: $paper,
            template: 'exam-paper',
            customerOnly: true,
            seo: ['title' => "{$title} · {$exam}"],
        );
    }
}
