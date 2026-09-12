<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionRequest;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;

/**
 * `answers.save` — the candidate's report for one case. Body:
 * `{ "attempt": <id>, "case": <case id>, "report": "<text>" }`.
 *
 * One row per case per enrolment, written in place. The source platform kept that rule in
 * application code and then had to de-duplicate scores downstream; here the table's unique
 * index is the rule and `updateOrCreate` is merely the polite way to obey it.
 *
 * Refused once the paper has ended, in either mode — a report is what was written *during* the
 * sitting, and the model answers have been shown. In timed mode a report arriving after the
 * clock ran out ends the paper on the spot and is refused with the reason, which is what the
 * source did and what a candidate would expect.
 */
class SaveAnswer extends CandidateAction
{
    public function rules(): array
    {
        return [
            'attempt' => ['required', 'integer', 'min:1'],
            'case'    => ['required', 'integer', 'min:1'],
            'report'  => ['present', 'nullable', 'string', 'max:20000'],
        ];
    }

    public function handle(ActionRequest $request): array
    {
        $attempt = $this->ownedAttempt($request->customer, (int) $request->input['attempt'], lock: true);
        $caseId  = (int) $request->input['case'];

        if (! in_array($caseId, array_map('intval', (array) ($attempt->case_ids ?? [])), true)) {
            $this->refuse('case', __('That case is not part of this paper.'));
        }

        if ($attempt->hasEnded()) {
            $this->refuse('attempt', __('This paper has ended, so its reports can no longer be changed.'));
        }

        $timed = ($attempt->enrolment->mode ?: Enrolment::MODE_PRACTICE) === Enrolment::MODE_TIMED;

        if ($timed && (int) $attempt->seconds_remaining <= 0 && ((int) $attempt->seconds_remaining + (int) $attempt->seconds_spent) > 0) {
            $attempt->ended_at = now();
            $attempt->save();
            $this->refreshStatus($attempt->enrolment);

            $this->refuse('attempt', __('Time is up. This paper has ended.'));
        }

        $answer = CaseAnswer::query()->updateOrCreate(
            ['enrolment_id' => $attempt->enrolment_id, 'case_id' => $caseId],
            ['attempt_id' => $attempt->id, 'report' => (string) ($request->input['report'] ?? '')]
        );

        return [
            'answer' => [
                'id'      => (int) $answer->id,
                'case_id' => (int) $answer->case_id,
                'report'  => (string) $answer->report,
                'score'   => $answer->score === null ? null : (float) $answer->score,
            ],
        ];
    }
}
