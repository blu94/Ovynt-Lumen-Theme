<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionRequest;
use Theme\Backend\Models\Enrolment;

/**
 * `attempts.progress` — the player's periodic ping. Body:
 * `{ "attempt": <id>, "seconds_spent": <int>, "case": <case id or null> }`.
 *
 * **The clock only moves one way.** The candidate's browser reports how long it believes the
 * paper has been open, and the server keeps the larger of that and what it already holds: a
 * ping that arrives out of order, a tab that was open in the past, or a client edited to report
 * zero cannot give time back. Time remaining is derived from the sitting's own frozen duration
 * (`seconds_remaining + seconds_spent` at any moment is the clock that was copied at open) and
 * likewise never grows. This is the source platform's rule, made explicit.
 *
 * In timed mode the paper ends itself when the clock reaches zero, here on the server, whatever
 * the page shows. Practice mode keeps counting and never ends anything — that is what practice
 * means.
 *
 * A ping for a sitting that has already ended is not an error: the timer may have fired on a
 * previous ping. The response says so, and the page redraws.
 */
class SaveProgress extends CandidateAction
{
    public function rules(): array
    {
        return [
            'attempt'       => ['required', 'integer', 'min:1'],
            'seconds_spent' => ['required', 'integer', 'min:0', 'max:172800'],
            'case'          => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function handle(ActionRequest $request): array
    {
        $attempt = $this->ownedAttempt($request->customer, (int) $request->input['attempt'], lock: true);

        if (! $attempt->hasEnded()) {
            $duration = (int) $attempt->seconds_remaining + (int) $attempt->seconds_spent;
            $spent    = max((int) $attempt->seconds_spent, (int) $request->input['seconds_spent']);

            $attempt->seconds_spent     = min($spent, $duration > 0 ? max($duration, $spent) : $spent);
            $attempt->seconds_remaining = max(0, $duration - $attempt->seconds_spent);

            $caseId = $request->input['case'] ?? null;

            if ($caseId !== null && in_array((int) $caseId, array_map('intval', (array) ($attempt->case_ids ?? [])), true)) {
                $visited = array_map('intval', (array) ($attempt->visited_case_ids ?? []));

                if (! in_array((int) $caseId, $visited, true)) {
                    $visited[] = (int) $caseId;
                    $attempt->visited_case_ids = $visited;
                }
            }

            $timed = ($attempt->enrolment->mode ?: Enrolment::MODE_PRACTICE) === Enrolment::MODE_TIMED;

            if ($timed && $duration > 0 && $attempt->seconds_remaining <= 0) {
                $attempt->ended_at = now();
            }

            $attempt->save();

            if ($attempt->hasEnded()) {
                $this->refreshStatus($attempt->enrolment);
            }
        }

        return [
            'seconds_spent'     => (int) $attempt->seconds_spent,
            'seconds_remaining' => (int) $attempt->seconds_remaining,
            'ended'             => $attempt->hasEnded(),
            'ended_at'          => $attempt->ended_at?->toIso8601String(),
        ];
    }
}
