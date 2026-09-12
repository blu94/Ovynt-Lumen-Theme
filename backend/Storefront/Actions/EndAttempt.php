<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionRequest;
use Theme\Backend\Models\CaseAnswer;

/**
 * `attempts.end` — the candidate ends a paper. Body: `{ "attempt": <id> }`.
 *
 * The moment the model answers become theirs. They are read live from the case rows here and
 * nowhere earlier — never in the snapshot, never on open, never on a ping — so a candidate
 * reading the network tab while still writing sees nothing they should not.
 *
 * Whether a paper may be ended with cases left blank is the operator's call, the theme setting
 * *Require Every Case Answered Before Ending*. On, an early end is refused and the sentence
 * says how many are blank; the timer still ends a timed paper regardless, so a candidate who
 * has run out of time is never stuck behind that rule. Off, they end when they like.
 *
 * Ending twice is not an error. A double click, a retry after a dropped connection — the second
 * request finds the paper already ended and returns the same answers.
 */
class EndAttempt extends CandidateAction
{
    public function rules(): array
    {
        return ['attempt' => ['required', 'integer', 'min:1']];
    }

    public function handle(ActionRequest $request): array
    {
        $attempt = $this->ownedAttempt($request->customer, (int) $request->input['attempt'], lock: true);

        if (! $attempt->hasEnded()) {
            $mustAnswerAll = (bool) $this->setting('require_all_answered_to_end', true);
            $timeIsUp      = (int) $attempt->seconds_remaining <= 0;

            if ($mustAnswerAll && ! $timeIsUp) {
                $caseIds = array_map('intval', (array) ($attempt->case_ids ?? []));

                $answered = CaseAnswer::query()
                    ->where('enrolment_id', $attempt->enrolment_id)
                    ->whereIn('case_id', $caseIds)
                    ->where('report', '!=', '')
                    ->count();

                $blank = count($caseIds) - $answered;

                if ($blank > 0) {
                    $this->refuse('attempt', trans_choice(
                        '{1} One case has no report yet. Report it, then end the paper.|[2,*] :count cases have no report yet. Report them, then end the paper.',
                        $blank,
                        ['count' => $blank]
                    ));
                }
            }

            $attempt->ended_at = now();
            $attempt->save();

            $this->refreshStatus($attempt->enrolment);
        }

        $locale = $request->locale;

        return [
            'attempt'       => $this->attemptPayload($attempt, $attempt->enrolment, $attempt->paper, $locale),
            'model_answers' => $this->modelAnswers($attempt, $locale),
            'answers'       => $this->answersPayload($attempt),
            'total'         => $this->totals($attempt),
            'enrolment'     => ['status' => $attempt->enrolment->status],
        ];
    }
}
