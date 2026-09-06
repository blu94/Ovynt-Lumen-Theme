<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\ExamCase;
use Theme\Backend\Models\PaperAttempt;
use Theme\Backend\Support\CurrentCandidate;

/**
 * The signed-in candidate's own exams.
 *
 * Server-rendered, which is possible because the storefront's bearer token is also set as an
 * HttpOnly cookie — see {@see CurrentCandidate}. A reader who is not signed in gets a prompt
 * rather than an empty list, because those are different situations and reading "you have no
 * exams" when you simply are not signed in is worse than being told to sign in.
 */
class CandidateDashboard
{
    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        $candidate = CurrentCandidate::get();

        if ($candidate === null) {
            return View::make($themeViewPath, [
                'signedIn'      => false,
                'heading'       => $data['heading'] ?? null,
                'signedOutText' => $data['signed_out_text'] ?? null,
                'rows'          => [],
                'emptyText'     => null,
            ])->render();
        }

        $showExpired = (bool) ($data['show_expired'] ?? true);

        $enrolments = Enrolment::query()
            ->with(['exam:id,title,slug,ideal_percent'])
            ->where('user_id', $candidate->id)
            ->newestFirst()
            ->get()
            // Only the newest enrolment per exam is the live one; the rest are history and
            // would otherwise show the same exam several times with different windows.
            ->unique('exam_id')
            ->filter(fn (Enrolment $e) => $showExpired || ! $e->hasExpired())
            ->values();

        if ($enrolments->isEmpty()) {
            return View::make($themeViewPath, [
                'signedIn'      => true,
                'heading'       => $data['heading'] ?? null,
                'signedOutText' => null,
                'rows'          => [],
                'emptyText'     => $data['empty_text'] ?? null,
            ])->render();
        }

        $examIds = $enrolments->pluck('exam_id')->all();

        // How many cases each exam actually serves, and what they are worth. One grouped query
        // rather than one per card — a dashboard with a dozen exams should not issue a dozen
        // counts.
        $totals = DB::table('lumen_cases')
            ->join('lumen_papers', 'lumen_papers.id', '=', 'lumen_cases.paper_id')
            ->whereIn('lumen_papers.exam_id', $examIds)
            ->whereNull('lumen_cases.deleted_at')
            ->whereNull('lumen_papers.deleted_at')
            ->where('lumen_cases.status', 'active')
            ->where('lumen_papers.status', 'active')
            ->groupBy('lumen_papers.exam_id')
            ->selectRaw('lumen_papers.exam_id, count(*) as cases, sum(lumen_cases.score_max) as max_score')
            ->get()
            ->keyBy('exam_id');

        // What the candidate has written and what they have marked, per enrolment.
        $answers = CaseAnswer::query()
            ->whereIn('enrolment_id', $enrolments->pluck('id'))
            ->selectRaw('enrolment_id, count(*) as answered, sum(case when score is null then 0 else score end) as scored')
            ->groupBy('enrolment_id')
            ->get()
            ->keyBy('enrolment_id');

        // Which papers have been ended — the score only counts papers that are finished, so a
        // candidate mid-paper is not shown a total that will change under them.
        $ended = PaperAttempt::query()
            ->whereIn('enrolment_id', $enrolments->pluck('id'))
            ->whereNotNull('ended_at')
            ->selectRaw('enrolment_id, count(*) as ended')
            ->groupBy('enrolment_id')
            ->get()
            ->keyBy('enrolment_id');

        $rows = $enrolments->map(function (Enrolment $e) use ($totals, $answers, $ended) {
            $total    = $totals->get($e->exam_id);
            $cases    = (int) ($total->cases ?? 0);
            $maxScore = (float) ($total->max_score ?? 0);
            $answered = (int) ($answers->get($e->id)->answered ?? 0);
            $scored   = (float) ($answers->get($e->id)->scored ?? 0);
            $expired  = $e->hasExpired();

            // Progress and score read zero once access has ended, because they describe what
            // the candidate can still act on. The numbers are not deleted — an operator can see
            // them on the enrolment, and buying again brings the candidate a fresh window
            // rather than resuming this one.
            $progress = ($expired || $cases === 0) ? 0.0 : round(min(100, ($answered / $cases) * 100), 1);

            return [
                'examTitle'  => $e->exam?->getTranslation('title', app()->getLocale(), false) ?? '—',
                'examSlug'   => $e->exam?->slug,
                'status'     => $expired ? 'expired' : $e->status,
                'mode'       => $e->mode,
                'expiresOn'  => $e->expires_at?->toFormattedDateString(),
                'daysLeft'   => $expired ? 0 : (int) max(0, round(now()->diffInDays($e->expires_at, false))),
                'cases'      => $cases,
                'answered'   => $expired ? 0 : $answered,
                'progress'   => $progress,
                'score'      => $expired ? 0.0 : round($scored, 2),
                'maxScore'   => round($maxScore, 2),
                'idealScore' => round($maxScore * ((int) ($e->exam->ideal_percent ?? 60) / 100), 2),
                'endedPapers'=> (int) ($ended->get($e->id)->ended ?? 0),
                'expired'    => $expired,
                // The one action that fits this enrolment's state.
                'action'     => match (true) {
                    $expired                       => 'renew',
                    $e->status === Enrolment::STATUS_COMPLETE => 'review',
                    $answered > 0                  => 'resume',
                    default                        => 'start',
                },
            ];
        })->values()->all();

        return View::make($themeViewPath, [
            'signedIn'      => true,
            'heading'       => $data['heading'] ?? null,
            'signedOutText' => null,
            'emptyText'     => null,
            'rows'          => $rows,
        ])->render();
    }
}
