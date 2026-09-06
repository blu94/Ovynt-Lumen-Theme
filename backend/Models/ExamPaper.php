<?php

namespace Theme\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * A timed set of cases — the unit a candidate opens, times and finishes.
 *
 * The exam's own duration is display; **this** duration is the clock that runs.
 */
class ExamPaper extends Model
{
    use SoftDeletes;
    use HasTranslations;
    use LogsSystemActivity;

    protected $table = 'lumen_papers';

    public $translatable = ['title', 'description'];

    protected $fillable = [
        'exam_id',
        'title',
        'slug',
        'description',
        'duration_minutes',
        'case_set_version',
        'status',
        'orders',
    ];

    protected $casts = [
        'exam_id'          => 'integer',
        'duration_minutes' => 'integer',
        'case_set_version' => 'integer',
        'orders'           => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ExamCase::class, 'paper_id');
    }

    /** What a new sitting is served. An inactive case leaves future sittings and no others. */
    public function activeCases(): HasMany
    {
        return $this->cases()->where('status', 'active')->orderBy('orders')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaperAttempt::class, 'paper_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('orders')->orderBy('id');
    }

    /** The clock, in the unit the attempt stores it in. */
    public function durationSeconds(): int
    {
        return max(0, (int) $this->duration_minutes) * 60;
    }

    /** The highest total this paper can yield, across the cases it actually serves. */
    public function maxScore(): float
    {
        return (float) $this->activeCases()->sum('score_max');
    }
}
