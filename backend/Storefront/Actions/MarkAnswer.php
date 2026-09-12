<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionRequest;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\ExamCase;
use Theme\Backend\Models\PaperAttempt;

/**
 * `answers.mark` — the candidate's self-mark for one report. Body:
 * `{ "answer": <answer id>, "score": <number> }`.
 *
 * Only after the paper has ended, because a mark is given against the model answer and the
 * model answer is only shown then. Validated against the ceiling the case carried **when it was
 * served** — the snapshot's `score_max`, not the live row — so an operator raising a case's
 * maximum next week does not make last week's marks wrong. Half marks are allowed, quarter
 * marks are not: the source platform validated the same steps.
 */
class MarkAnswer extends CandidateAction
{
    public function rules(): array
    {
        return [
            'answer' => ['required', 'integer', 'min:1'],
            'score'  => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function handle(ActionRequest $request): array
    {
        $answer = CaseAnswer::query()->with('enrolment')->lockForUpdate()->find((int) $request->input['answer']);

        if ($answer === null || $answer->enrolment === null || (int) $answer->enrolment->user_id !== (int) $request->customer->id) {
            $this->refuse('answer', __('You do not have access to this exam.'));
        }

        if ($answer->enrolment->hasExpired()) {
            $this->refuse('answer', __('Your access to this exam has ended.'));
        }

        $attempt = $answer->attempt_id ? PaperAttempt::query()->find($answer->attempt_id) : null;

        if ($attempt === null || ! $attempt->hasEnded()) {
            $this->refuse('answer', __('Mark a case once the paper has ended.'));
        }

        $snapshot = collect((array) ($attempt->case_snapshot ?? []))->firstWhere('id', (int) $answer->case_id);
        $max = $snapshot !== null
            ? (float) ($snapshot['score_max'] ?? 0)
            : (float) (ExamCase::withTrashed()->find($answer->case_id)?->score_max ?? 0);

        $score = (float) $request->input['score'];

        if ($score > $max) {
            $this->refuse('score', __('The most this case can score is :max.', ['max' => rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.')]));
        }

        if (abs(($score * 2) - round($score * 2)) > 0.0001) {
            $this->refuse('score', __('Marks are given in halves: 3, 3.5, 4.'));
        }

        $answer->score     = $score;
        $answer->scored_at = now();
        $answer->save();

        return [
            'answer' => [
                'id'      => (int) $answer->id,
                'case_id' => (int) $answer->case_id,
                'score'   => (float) $answer->score,
            ],
            'total' => $this->totals($attempt),
        ];
    }
}
