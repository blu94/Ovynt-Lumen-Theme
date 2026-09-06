<?php

namespace Theme\Backend\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\Exam;
use Theme\Backend\Models\PaperAttempt;
use Theme\Backend\Support\ResolvesListFilters;

/**
 * The Enrolments screen's server side — the support desk's view of who has access to what.
 *
 * This is the module staff reach for when a candidate says "I paid and I can't get in", so it
 * favours showing what actually happened over letting anybody edit it. Granting is allowed;
 * rewriting somebody's history is not.
 */
class EnrolmentRepository
{
    use ResolvesListFilters;

    public function baseIndexQuery(array $filters = [])
    {
        $query = Enrolment::query()
            ->with(['exam:id,title,slug'])
            ->newestFirst();

        if (($status = $this->scalarFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        if (($examId = $this->idFilter($filters, 'exam_id')) !== null) {
            $query->where('exam_id', $examId);
        }

        if (($userId = $this->idFilter($filters, 'user_id')) !== null) {
            $query->where('user_id', $userId);
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            // The support question is almost always "find me this person", and they are named
            // in `users`, not here. Resolved to ids rather than joined, so the list query stays
            // one table and the search works the same whether or not the user row survives.
            $userIds = User::query()
                ->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                })
                ->pluck('id');

            $query->where(function ($q) use ($userIds, $term) {
                $q->whereIn('user_id', $userIds)
                    ->orWhereHas('exam', fn ($e) => $e->where('title', 'like', "%{$term}%"));
            });
        }

        return $query;
    }

    public function find($id)
    {
        $enrolment = Enrolment::query()->with(['exam:id,title,slug'])->findOrFail($id);

        // Who this is, resolved for display. Not a relation on the model: `users` is core's
        // table and this theme deliberately holds no foreign key into it, so a candidate whose
        // account was deleted still shows an enrolment rather than a 500.
        $user = User::query()->find($enrolment->user_id);

        $enrolment->setAttribute('candidate_name', $user?->name ?? "Deleted account #{$enrolment->user_id}");
        $enrolment->setAttribute('candidate_email', $user?->email ?? '—');

        // **The evidence a dispute actually needs.** One row per paper: how long they spent,
        // how much was left, how many cases they answered and whether it ended. Set here rather
        // than as a model append, because the list loads enrolments too and must not pay for it.
        $attempts = PaperAttempt::query()
            ->with('paper:id,title,duration_minutes')
            ->where('enrolment_id', $enrolment->id)
            ->orderBy('id')
            ->get();

        $answered = CaseAnswer::query()
            ->where('enrolment_id', $enrolment->id)
            ->selectRaw('attempt_id, count(*) as n, sum(case when score is null then 0 else 1 end) as marked')
            ->groupBy('attempt_id')
            ->get()
            ->keyBy('attempt_id');

        $enrolment->setAttribute('paper_progress', $attempts->map(function (PaperAttempt $a) use ($answered) {
            $counts = $answered->get($a->id);

            return [
                'paper'       => $a->paper?->getTranslation('title', app()->getLocale(), false) ?? "#{$a->paper_id}",
                'spent'       => gmdate('H:i:s', max(0, (int) $a->seconds_spent)),
                'left'        => gmdate('H:i:s', max(0, (int) $a->seconds_remaining)),
                'answered'    => (int) ($counts->n ?? 0),
                'marked'      => (int) ($counts->marked ?? 0),
                'ended'       => $a->ended_at?->toDateTimeString() ?? '—',
            ];
        })->values()->all());

        return $enrolment;
    }

    /**
     * Grant access by hand — the support route.
     *
     * Deliberately bypasses the purchase gate: this exists to comp a candidate, to make good on
     * a payment that failed to settle, and to extend somebody whose window lapsed mid-sitting.
     * Every one of those is a decision a person makes, which is why it is a screen behind a
     * permission rather than a rule.
     */
    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $userId = (int) ($data['user_id'] ?? 0);
            $examId = (int) ($data['exam_id'] ?? 0);

            $exam = Exam::findOrFail($examId);

            abort_unless(
                User::query()->whereKey($userId)->exists(),
                422,
                'That account no longer exists, so there is nobody to grant access to.'
            );

            // An expiry the operator typed wins; otherwise the exam's own window from today.
            $expires = ! empty($data['expires_at'])
                ? \Illuminate\Support\Carbon::parse($data['expires_at'])
                : now()->addDays(max(1, (int) ($exam->access_days ?: 30)));

            $enrolment = Enrolment::create([
                'user_id'    => $userId,
                'exam_id'    => $examId,
                // No order: a hand grant is not a purchase, and recording one would make the
                // sales figures include something nobody paid for.
                'order_id'   => null,
                'started_at' => now(),
                'expires_at' => $expires,
                'status'     => Enrolment::STATUS_ACTIVE,
                'mode'       => $data['mode'] ?? Enrolment::MODE_PRACTICE,
            ]);

            $this->log($enrolment, 'Granted access by hand', [
                'expires_at' => $expires->toDateTimeString(),
                'reason'     => $data['reason'] ?? null,
            ]);

            return $enrolment;
        });
    }

    /**
     * Only the window, the state and the mode may be edited.
     *
     * Moving an enrolment to a different candidate or a different exam is not an edit — it is
     * two decisions pretending to be one, and it would silently reassign somebody's answers.
     * Both keys are absent from the allow-list, so the form cannot offer them and a crafted
     * request cannot smuggle them.
     */
    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $enrolment = Enrolment::findOrFail($id);
            $before    = $enrolment->only(['expires_at', 'status', 'mode']);

            $enrolment->update(collect($data)->only(['expires_at', 'status', 'mode'])->all());

            $after = $enrolment->only(['expires_at', 'status', 'mode']);

            if ($before != $after) {
                $this->log($enrolment, 'Changed an enrolment', ['from' => $before, 'to' => $after]);
            }

            return $enrolment->fresh();
        });
    }

    /**
     * **Refused, always.**
     *
     * Deleting an enrolment would cascade its attempts and answers — the record of what a
     * candidate sat and wrote. There is no operator need this serves that setting the status to
     * Expired does not serve better, and the difference between the two is that one is
     * reversible.
     */
    public function delete($id)
    {
        abort(403, 'An enrolment cannot be deleted — it is the record of what a candidate sat, and deleting it would take their answers with it. Set its status to Expired instead, which closes access and keeps the history.');
    }

    public function getOptions(array $columns = [])
    {
        return [
            'status' => [
                ['title' => 'Active',   'value' => Enrolment::STATUS_ACTIVE],
                ['title' => 'Complete', 'value' => Enrolment::STATUS_COMPLETE],
                ['title' => 'Expired',  'value' => Enrolment::STATUS_EXPIRED],
            ],
            'mode' => [
                ['title' => 'Practice', 'value' => Enrolment::MODE_PRACTICE],
                ['title' => 'Timed',    'value' => Enrolment::MODE_TIMED],
            ],
            'exams' => Exam::query()->ordered()->get()->map(fn (Exam $e) => [
                'title' => $e->getTranslation('title', app()->getLocale(), false) ?: $e->slug,
                'value' => $e->id,
            ])->all(),
        ];
    }

    protected function log(Enrolment $enrolment, string $what, array $properties): void
    {
        try {
            activity()->performedOn($enrolment)->withProperties(array_filter($properties))->log($what);
        } catch (\Throwable $e) {
            // An audit trail must never be the thing that stops an operator helping somebody.
            report($e);
        }
    }
}
