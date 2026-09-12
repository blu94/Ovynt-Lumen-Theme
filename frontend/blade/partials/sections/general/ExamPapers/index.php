<?php

namespace Theme\Sections\General;

use App\Models\Product;
use Illuminate\Support\Facades\View;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\Exam;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Models\PaperAttempt;
use Theme\Backend\Support\CurrentCandidate;
use Theme\Backend\Support\TranslatesSectionData;

/**
 * One exam's papers, for the candidate who holds access to it.
 *
 * **Which exam.** The address is `/exam/{slug}`, served by core's `storefront.paths` seam
 * through `backend/Storefront/ExamPath.php`: the product that resolved is shared as `$page`,
 * and this driver reads it from there. The Page with slug `exam` still supplies the layout —
 * this block sits on it — and answers the bare `/exam` chooser. `?e={slug}` is kept as a
 * fallback for links written before the seam existed (`ISSUES-CORE.md` C8, now built), and
 * for a core too old to have it.
 *
 * With neither it does not error. One enrolment goes straight through; several render a
 * chooser; none sends the reader back to the catalogue.
 */
class ExamPapers
{
    use TranslatesSectionData;

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data      = $data ?? [];
        $candidate = CurrentCandidate::get();

        $view = fn (array $extra) => View::make($themeViewPath, array_merge([
            'signedIn'       => $candidate !== null,
            'chooserHeading' => $this->translate($data['chooser_heading'] ?? null, $locale),
            'lockedText'     => $this->translate($data['locked_text'] ?? null, $locale),
            'showScores'     => (bool) ($data['show_scores'] ?? true),
            'exam'           => null,
            'papers'         => [],
            'choices'        => [],
            'state'          => 'signed-out',
        ], $extra))->render();

        if ($candidate === null) {
            return $view([]);
        }

        // Every exam this candidate holds, newest enrolment per exam. The chooser needs it and
        // so does the access check below, so it is fetched once either way.
        $enrolments = Enrolment::query()
            ->with(['product:id,title,slug'])
            ->where('user_id', $candidate->id)
            ->newestFirst()
            ->get()
            ->unique('product_id');

        // The resolved product first (storefront.paths shares it as `page`), the query string
        // only when there is none. Matched by id when the record is known: a slug comparison
        // across locales is a guess, an id is not.
        $record   = View::shared('page');
        $wantedId = $record instanceof Product ? (int) $record->id : null;
        $wanted   = $wantedId !== null ? (string) $record->slug : trim((string) request()->query('e', ''));

        $enrolment = $wantedId !== null
            ? $enrolments->first(fn (Enrolment $e) => (int) $e->product_id === $wantedId)
            : ($wanted !== ''
                ? $enrolments->first(fn (Enrolment $e) => $e->product?->slug === $wanted)
                : ($enrolments->count() === 1 ? $enrolments->first() : null));

        if ($enrolment === null) {
            // Asked for an exam they do not hold — including one that does not exist. The two
            // are answered identically on purpose: "you do not have access" tells a stranger
            // nothing about which exams exist.
            if ($wanted !== '') {
                return $view(['state' => 'no-access']);
            }

            return $view([
                'state'   => $enrolments->isEmpty() ? 'none' : 'choose',
                'choices' => $enrolments->map(fn (Enrolment $e) => [
                    'title' => $e->product?->getTranslation('title', app()->getLocale(), false) ?? '—',
                    'slug'  => $e->product?->slug,
                ])->filter(fn ($c) => $c['slug'] !== null)->values()->all(),
            ]);
        }

        $exam = $enrolment->product;

        if ($enrolment->hasExpired()) {
            return $view([
                'state' => 'expired',
                'exam'  => [
                    'title'     => $exam?->getTranslation('title', app()->getLocale(), false) ?? '—',
                    'slug'      => $exam?->slug,
                    'expiresOn' => $enrolment->expires_at?->toFormattedDateString(),
                ],
            ]);
        }

        $papers = ExamPaper::query()
            ->where('product_id', $enrolment->product_id)
            ->where('status', 'active')
            ->withCount(['cases' => fn ($q) => $q->where('status', 'active')])
            ->ordered()
            ->get();

        // The candidate's own attempts for these papers — the newest per paper, because a
        // paper opened twice has two rows and only the current one describes where they are.
        $attempts = PaperAttempt::query()
            ->where('enrolment_id', $enrolment->id)
            ->whereIn('paper_id', $papers->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('paper_id')
            ->keyBy('paper_id');

        $answers = CaseAnswer::query()
            ->where('enrolment_id', $enrolment->id)
            ->selectRaw('attempt_id, count(*) as answered, sum(case when score is null then 0 else score end) as scored')
            ->groupBy('attempt_id')
            ->get()
            ->keyBy('attempt_id');

        $rows = $papers->map(function (ExamPaper $p) use ($attempts, $answers) {
            $attempt  = $attempts->get($p->id);
            $counts   = $attempt ? $answers->get($attempt->id) : null;
            $answered = (int) ($counts->answered ?? 0);
            $ended    = $attempt?->hasEnded() ?? false;

            return [
                'title'    => $p->getTranslation('title', app()->getLocale(), false) ?: $p->slug,
                'slug'     => $p->slug,
                'minutes'  => (int) $p->duration_minutes,
                'cases'    => (int) $p->cases_count,
                'answered' => $answered,
                'ended'    => $ended,
                'endedOn'  => $attempt?->ended_at?->toFormattedDateString(),
                'score'    => $ended ? round((float) ($counts->scored ?? 0), 2) : null,
                'maxScore' => round((float) $p->maxScore(), 2),
                // Three states, and each is a different sentence to the candidate.
                'state'    => $ended ? 'review' : ($attempt ? 'resume' : 'start'),
            ];
        })->values()->all();

        return $view([
            'state'  => 'ok',
            'exam'   => [
                'title'     => $exam?->getTranslation('title', app()->getLocale(), false) ?? '—',
                'slug'      => $exam?->slug,
                'mode'      => $enrolment->mode,
                'expiresOn' => $enrolment->expires_at?->toFormattedDateString(),
            ],
            'papers' => $rows,
        ]);
    }
}
