<?php

namespace Theme\Backend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a candidate wrote for one case, and what they later gave themselves for it.
 *
 * One row per case per enrolment, enforced by a unique index rather than by remembering to
 * update in place. The source enforces the same rule in application code and then has to
 * de-duplicate scores to the highest per case when rolling up — which is what a missing
 * constraint looks like from downstream.
 */
class CaseAnswer extends Model
{
    protected $table = 'lumen_answers';

    protected $fillable = [
        'enrolment_id',
        'attempt_id',
        'case_id',
        'report',
        'score',
        'scored_at',
    ];

    protected $casts = [
        'enrolment_id' => 'integer',
        'attempt_id'   => 'integer',
        'case_id'      => 'integer',
        'score'        => 'decimal:2',
        'scored_at'    => 'datetime',
    ];

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class, 'enrolment_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaperAttempt::class, 'attempt_id');
    }

    /**
     * The case this answers, **including a deleted one**.
     *
     * An answer outlives the case it was written for. Resolving without the trashed rows would
     * make a finished sitting render blank the moment staff tidied the case bank.
     */
    public function examCase(): BelongsTo
    {
        return $this->belongsTo(ExamCase::class, 'case_id')->withTrashed();
    }

    /** Written is not the same as marked: a report with no score is a case awaiting its mark. */
    public function isScored(): bool
    {
        return $this->score !== null;
    }
}
