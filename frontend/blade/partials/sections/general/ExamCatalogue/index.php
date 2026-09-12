<?php

namespace Theme\Sections\General;

use App\Models\Product;
use App\Repositories\Setting\Application\ApplicationInterface;
use Illuminate\Support\Facades\View;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\Exam;
use Theme\Backend\Support\CurrentCandidate;
use Theme\Backend\Support\TranslatesSectionData;

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
    use TranslatesSectionData;

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $limit = (int) ($data['limit'] ?? 0);

        // **The exam is the product.** One query over the catalogue, narrowed to products
        // carrying an exam row that is switched on. Title, price, slug and status all come from
        // the product itself, so a card cannot disagree with the product page it links to.
        $products = Product::query()
            ->where('status', 'active')
            ->whereIn('id', Exam::query()->isExam()->select('product_id'))
            ->orderBy('orders')->orderBy('id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get(['id', 'title', 'subtitle', 'description', 'price', 'slug']);

        $exams = Exam::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        // Which of these the reader already has. Anonymous readers get an empty set and every
        // card reads as "buy" — which is correct, not a degraded state.
        $candidateId = CurrentCandidate::id();

        $enrolled = $candidateId === null
            ? collect()
            : Enrolment::query()
                ->where('user_id', $candidateId)
                ->whereIn('product_id', $products->pluck('id'))
                ->newestFirst()
                ->get()
                ->groupBy('product_id')
                // The newest enrolment is the current one; older rows are history.
                ->map(fn ($rows) => $rows->first());

        $appSettings = app(ApplicationInterface::class)->getSettings();

        $cards = $products->map(function (Product $product) use ($exams, $enrolled) {
            $exam      = $exams->get($product->id);
            $enrolment = $enrolled->get($product->id);

            // Four states, and each answers a different question for the reader:
            //   open    — they have live access, so the only useful action is to go and sit it
            //   renew   — they had access and it lapsed; buying again is what restores it
            //   buy     — it is for sale and they do not have it
            //   free    — nothing sells it, so there is nothing to buy
            // Free is the product's own price, not a flag beside it — one figure, so the card
            // and the checkout cannot disagree.
            $free = (float) ($product->price ?? 0) <= 0;

            $state = match (true) {
                $enrolment !== null && ! $enrolment->hasExpired() => 'open',
                $enrolment !== null                               => 'renew',
                $free                                             => 'free',
                default                                           => 'buy',
            };

            return [
                'id'          => $product->id,
                'slug'        => $product->slug,
                'title'       => $product->getTranslation('title', app()->getLocale(), false) ?: $product->slug,
                'subtitle'    => $product->getTranslation('subtitle', app()->getLocale(), false),
                'description' => $product->getTranslation('description', app()->getLocale(), false),
                'papers'      => $exam ? $exam->activePapers()->count() : 0,
                'accessDays'  => (int) ($exam->access_days ?? 30),
                'duration'    => ($exam && $exam->duration_minutes) ? (int) $exam->duration_minutes : null,
                'price'       => $product->price,
                'productSlug' => $product->slug,
                'state'       => $state,
                'expiresOn'   => $enrolment?->expires_at?->toFormattedDateString(),
            ];
        })->values()->all();

        return View::make($themeViewPath, [
            'heading'          => $this->translate($data['heading'] ?? null, $locale),
            'intro'            => $this->translate($data['intro'] ?? null, $locale),
            'showPrice'        => (bool) ($data['show_price'] ?? true),
            'cards'            => $cards,
            'currencySymbol'   => $appSettings['currency_symbol'] ?? '$',
            'currencyPosition' => $appSettings['currency_position'] ?? 'prefix',
            'signedIn'         => $candidateId !== null,
        ])->render();
    }
}
