<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\Exam;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Models\PaperAttempt;
use Theme\Backend\Support\CurrentCandidate;

/**
 * One exam's papers, for the candidate who holds access to it.
 *
 * **Why this reads a query parameter instead of a path segment.** A theme cannot add a
 * storefront route, and core's path resolution is a fixed list — a page slug, then `blog/`,
 * `collections/` and `products/` (`ThemeController::render()`). `PathNotResolved` is a
 * *redirect* seam and says so: a listener "cannot write the response". So `/exam/{slug}` is
 * unreachable by any package, and the only address a theme can serve is one core already
 * resolves: an ordinary page.
 *
 * The page is therefore `/exam` — a real Page the operator creates, carrying this section —
 * and the exam is named in `?e={slug}`. Ugly next to `/exam/rapid-reporting`, and honest:
 * a prettier URL needs the core seam recorded as `ISSUES-CORE.md` C8.
 *
 * With no `?e=` it does not error. One enrolment goes straight through; several render a
 * chooser; none sends the reader back to the catalogue.
 */
class ExamPapers
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data      = $data ?? [];
        $candidate = CurrentCandidate::get();

        $view = fn (array $extra) => View::make($themeViewPath, array_merge([
            'signedIn'       => $candidate !== null,
            'chooserHeading' => $data['chooser_heading'] ?? null,
            'lockedText'     => $data['locked_text'] ?? null,
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
            ->with(['exam:id,title,slug,ideal_percent'])
            ->where('user_id', $candidate->id)
            ->newestFirst()
            ->get()
            ->unique('exam_id');

        $wanted = trim((string) request()->query('e', ''));

        $enrolment = $wanted !== ''
            ? $enrolments->first(fn (Enrolment $e) => $e->exam?->slug === $wanted)
            : ($enrolments->count() === 1 ? $enrolments->first() : null);

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
                    'title' => $e->exam?->getTranslation('title', app()->getLocale(), false) ?? '—',
                    'slug'  => $e->exam?->slug,
                ])->filter(fn ($c) => $c['slug'] !== null)->values()->all(),
            ]);
        }

        $exam = $enrolment->exam;

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
            ->where('exam_id', $enrolment->exam_id)
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
