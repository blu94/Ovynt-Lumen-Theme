<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Models\Testimonial;
use Theme\Backend\Support\TranslatesSectionData;

/**
 * Published quotes from the Testimonials module.
 *
 * **Read from the module, not from a repeater on the block — and that is a deliberate
 * divergence from Saffron.** Saffron's quotes are page furniture: chosen for one page, edited
 * on that page, and its driver says so. Here a testimonial is something a candidate sent in and
 * staff reviewed, and the same handful of quotes belongs on the home page, the catalogue and a
 * landing page alike. One library, published rows only, in the order staff set; the block
 * decides how many and how wide.
 */
class Testimonials
{
    use TranslatesSectionData;

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $limit   = max(1, min(30, (int) ($data['limit'] ?? 6)));
        $columns = (string) ($data['columns'] ?? '3') === '2' ? 2 : 3;

        $items = Testimonial::query()
            ->published()
            ->ordered()
            ->limit($limit)
            ->get()
            ->map(fn (Testimonial $t) => [
                'quote'  => trim($t->getTranslation('quote', $locale, false) ?: $t->getTranslation('quote', 'en', false)),
                'author' => trim((string) $t->author),
                'role'   => trim((string) $t->role),
                // Clamped rather than trusted: the select offers 0–5, and a hand-edited row could
                // ask for nine stars.
                'rating' => max(0, min(5, (int) ($t->rating ?? 0))),
            ])
            ->filter(fn (array $item) => $item['quote'] !== '')
            ->values()
            ->all();

        return View::make($themeViewPath, [
            'heading' => $this->translate($data['heading'] ?? null, $locale),
            'intro'   => $this->translate($data['intro'] ?? null, $locale),
            'items'   => $items,
            'columns' => $columns,
        ])->render();
    }
}
