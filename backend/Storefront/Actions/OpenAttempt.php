<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionRequest;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Models\PaperAttempt;

/**
 * `attempts.open` — start or resume a paper. Body: `{ "paper": "<paper slug>" }`.
 *
 * The write that freezes a sitting. The first open creates the attempt: the ordered case ids,
 * a snapshot of each case as served, the paper's clock copied onto the row. Every later open of
 * the same paper — a refresh, a crash, a review a week later — returns that same attempt, so a
 * case edited or deleted afterwards never rewrites what this candidate sat. That is the source
 * platform's one-attempt-per-package rule, kept.
 *
 * The response carries everything the player needs to draw itself: the attempt and its clock,
 * the cases without their model answers, whatever the candidate has already written, and — only
 * once the paper has ended — the model answers. The theme settings the player has to honour
 * travel with it, so the page does not need a second request to learn how often to ping.
 */
class OpenAttempt extends CandidateAction
{
    public function rules(): array
    {
        return ['paper' => ['required', 'string', 'max:191']];
    }

    public function handle(ActionRequest $request): array
    {
        $paper = ExamPaper::query()->active()->where('slug', $request->input['paper'])->first();

        if ($paper === null) {
            // The same sentence as an exam the candidate does not hold: a slug that does not
            // exist must not read differently from one they cannot open.
            $this->refuse('paper', __('You do not have access to this exam.'));
        }

        // Locked for the create below: two tabs opening the paper at once must end up on one
        // attempt, not two sittings of the same paper with different clocks.
        $enrolment = $this->enrolmentFor($request->customer, (int) $paper->product_id, lock: true);

        $attempt = PaperAttempt::query()
            ->where('enrolment_id', $enrolment->id)
            ->where('paper_id', $paper->id)
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            $cases = $paper->activeCases()->with('images')->get();

            if ($cases->isEmpty()) {
                $this->refuse('paper', __('This paper has no cases yet.'));
            }

            $attempt = PaperAttempt::create([
                'enrolment_id'      => $enrolment->id,
                'paper_id'          => $paper->id,
                'case_ids'          => $cases->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'case_snapshot'     => PaperAttempt::buildSnapshot($cases),
                'case_set_version'  => max(1, (int) $paper->case_set_version),
                // The clock that will run, copied now: a paper shortened next week must not
                // shorten a sitting that is already under way.
                'seconds_remaining' => $paper->durationSeconds(),
                'seconds_spent'     => 0,
                'visited_case_ids'  => [],
                'started_at'        => now(),
            ]);
        }

        $locale = $request->locale;

        return [
            'attempt'       => $this->attemptPayload($attempt, $enrolment, $paper, $locale),
            'cases'         => $this->casesPayload($attempt, $locale),
            'answers'       => $this->answersPayload($attempt),
            'model_answers' => $attempt->hasEnded() ? $this->modelAnswers($attempt, $locale) : null,
            'total'         => $attempt->hasEnded() ? $this->totals($attempt) : null,
            'settings'      => [
                'progress_ping_seconds'       => max(3, (int) $this->setting('progress_ping_seconds', 10)),
                'require_all_answered_to_end' => (bool) $this->setting('require_all_answered_to_end', true),
                'allow_mode_reversal'         => (bool) $this->setting('allow_mode_reversal', false),
            ],
        ];
    }
}
