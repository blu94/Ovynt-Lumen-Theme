<?php

namespace Theme\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * The thing a candidate buys.
 *
 * An exam owns its papers and its access window. It does **not** own its price — that lives on
 * the core product named by `product_id`, so the figure a customer is charged and the figure
 * the exam believes in are the same figure by construction. See the migration for why.
 */
class Exam extends Model
{
    use SoftDeletes;
    use HasTranslations;
    use LogsSystemActivity;

    protected $table = 'lumen_exams';

    public $translatable = ['title', 'subtitle', 'description'];

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'description',
        'product_id',
        'duration_minutes',
        'access_days',
        'ideal_percent',
        'status',
        'orders',
    ];

    protected $casts = [
        'product_id'       => 'integer',
        'duration_minutes' => 'integer',
        'access_days'      => 'integer',
        'ideal_percent'    => 'integer',
        'orders'           => 'integer',
    ];

    public function papers(): HasMany
    {
        return $this->hasMany(ExamPaper::class, 'exam_id');
    }

    /** The papers a candidate is actually served — inactive ones vanish from new sittings. */
    public function activePapers(): HasMany
    {
        return $this->papers()->where('status', 'active')->orderBy('orders')->orderBy('id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'exam_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('orders')->orderBy('id');
    }

    /**
     * An exam nothing sells is free.
     *
     * Deliberately derived rather than stored: a stored `is_free` flag and a `product_id` are
     * two answers to one question, and the shop has already been bitten by that shape.
     */
    public function isFree(): bool
    {
        return $this->product_id === null;
    }

    /** The highest total a candidate could score, across the cases they are actually served. */
    public function maxScore(): float
    {
        return (float) ExamCase::query()
            ->where('status', 'active')
            ->whereIn('paper_id', $this->activePapers()->pluck('id'))
            ->whereNull('deleted_at')
            ->sum('score_max');
    }
}
