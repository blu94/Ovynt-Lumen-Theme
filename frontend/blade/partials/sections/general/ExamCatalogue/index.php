<?php

namespace Theme\Sections\General;

use App\Models\Product;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\Exam;
use Theme\Backend\Support\CurrentCandidate;

/**
 * The exams on sale.
 *
 * **The namespace is not derived from the file path.** Core requires the driver file and then
 * asks for `\Theme\Sections\{Group}\{ClassName}` by name, so a class in the right directory
 * under the wrong namespace is silently never found and the section renders nothing. A theme's
 * own section type always resolves to the `general` group, whatever the schema says, which is
 * why this lives under `partials/sections/general/`.
 */
class ExamCatalogue
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $limit = (int) ($data['limit'] ?? 0);

        $exams = Exam::query()
            ->active()
            ->ordered()
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get();

        // Prices come from the linked products, in one query rather than one per card.
        $products = Product::query()
            ->whereIn('id', $exams->pluck('product_id')->filter()->all())
            ->get(['id', 'price', 'slug'])
            ->keyBy('id');

        // Which of these the reader already has. Anonymous readers get an empty set and every
        // card reads as "buy" — which is correct, not a degraded state.
        $candidateId = CurrentCandidate::id();

        $enrolled = $candidateId === null
            ? collect()
            : Enrolment::query()
                ->where('user_id', $candidateId)
                ->whereIn('exam_id', $exams->pluck('id'))
                ->newestFirst()
                ->get()
                ->groupBy('exam_id')
                // The newest enrolment is the current one; older rows are history.
                ->map(fn ($rows) => $rows->first());

        $appSettings = app(ApplicationInterface::class)->getSettings();

        $cards = $exams->map(function (Exam $exam) use ($products, $enrolled) {
            $product   = $exam->product_id ? $products->get($exam->product_id) : null;
            $enrolment = $enrolled->get($exam->id);

            // Four states, and each answers a different question for the reader:
            //   open    — they have live access, so the only useful action is to go and sit it
            //   renew   — they had access and it lapsed; buying again is what restores it
            //   buy     — it is for sale and they do not have it
            //   free    — nothing sells it, so there is nothing to buy
            $state = match (true) {
                $enrolment !== null && ! $enrolment->hasExpired() => 'open',
                $enrolment !== null                               => 'renew',
                $exam->isFree()                                   => 'free',
                default                                           => 'buy',
            };

            return [
                'id'          => $exam->id,
                'slug'        => $exam->slug,
                'title'       => $exam->getTranslation('title', app()->getLocale(), false) ?: $exam->slug,
                'subtitle'    => $exam->getTranslation('subtitle', app()->getLocale(), false),
                'description' => $exam->getTranslation('description', app()->getLocale(), false),
                'papers'      => $exam->activePapers()->count(),
                'accessDays'  => (int) $exam->access_days,
                'duration'    => $exam->duration_minutes ? (int) $exam->duration_minutes : null,
                'price'       => $product?->price,
                'productSlug' => $product?->slug,
                'state'       => $state,
                'expiresOn'   => $enrolment?->expires_at?->toFormattedDateString(),
            ];
        })->values()->all();

        return View::make($themeViewPath, [
            'heading'          => $data['heading'] ?? null,
            'intro'            => $data['intro'] ?? null,
            'showPrice'        => (bool) ($data['show_price'] ?? true),
            'cards'            => $cards,
            'currencySymbol'   => $appSettings['currency_symbol'] ?? '$',
            'currencyPosition' => $appSettings['currency_position'] ?? 'prefix',
            'signedIn'         => $candidateId !== null,
        ])->render();
    }
}
