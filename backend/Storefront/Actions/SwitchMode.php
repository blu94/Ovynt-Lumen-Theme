<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionRequest;
use App\Models\Product;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\PaperAttempt;

/**
 * `enrolments.mode` — practice or timed. Body: `{ "exam": "<product slug>", "mode": "practice"|"timed" }`.
 *
 * **Switching clears everything.** Moving to timed deletes every report and every sitting the
 * candidate has under this enrolment and restarts every clock — the page warns them first, the
 * theme setting's hint says so, and the source platform did the same. There is no way to carry
 * practice answers into a timed sitting, because then a timed score would not mean what it says.
 *
 * Going back to practice is refused unless the operator has switched *Allow Returning to
 * Practice* on. The source carried a flag permitting it that only changed the wording and
 * cleared nothing, which let a candidate keep timed answers under an untimed label; when it is
 * allowed here it clears just as the other direction does.
 */
class SwitchMode extends CandidateAction
{
    public function rules(): array
    {
        return [
            'exam' => ['required', 'string', 'max:191'],
            'mode' => ['required', 'in:' . Enrolment::MODE_PRACTICE . ',' . Enrolment::MODE_TIMED],
        ];
    }

    public function handle(ActionRequest $request): array
    {
        $locale   = $request->locale;
        $fallback = (string) config('app.fallback_locale', 'en');
        $slug     = $request->input['exam'];

        $product = Product::query()
            ->where(function ($q) use ($locale, $fallback, $slug) {
                $q->whereJsonContains("slug->{$locale}", $slug)
                    ->orWhereJsonContains("slug->{$fallback}", $slug);
            })
            ->first();

        if ($product === null) {
            $this->refuse('exam', __('You do not have access to this exam.'));
        }

        $enrolment = $this->enrolmentFor($request->customer, (int) $product->id, lock: true);

        $current = $enrolment->mode ?: Enrolment::MODE_PRACTICE;
        $target  = $request->input['mode'];

        if ($current === $target) {
            return ['mode' => $current, 'cleared' => 0];
        }

        if ($target === Enrolment::MODE_PRACTICE && ! (bool) $this->setting('allow_mode_reversal', false)) {
            $this->refuse('mode', __('You cannot switch back to practice mode after a timed sitting.'));
        }

        $cleared = CaseAnswer::query()->where('enrolment_id', $enrolment->id)->delete();
        PaperAttempt::query()->where('enrolment_id', $enrolment->id)->delete();

        $enrolment->mode   = $target;
        $enrolment->status = Enrolment::STATUS_ACTIVE;
        $enrolment->save();

        return ['mode' => $enrolment->mode, 'cleared' => (int) $cleared];
    }
}
