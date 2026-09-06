<?php

namespace Theme\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * One candidate's granted access to one exam, for one window.
 *
 * Rows are never reused. A lapsed candidate who buys again gets a new enrolment; the old one
 * keeps its dates and its answers, which is the only reason "what did I score last time" can
 * be answered at all.
 */
class Enrolment extends Model
{
    use HasTranslations;
    use LogsSystemActivity;

    protected $table = 'lumen_enrolments';

    /**
     * Nothing on this model is translatable, but `LogsSystemActivity` and the admin list both
     * ask a model whether it is, so the trait is declared with an empty list rather than the
     * question being left to chance.
     */
    public $translatable = [];

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_EXPIRED  = 'expired';

    public const MODE_PRACTICE = 'practice';
    public const MODE_TIMED    = 'timed';

    protected $fillable = [
        'user_id',
        'product_id',
        'order_id',
        'started_at',
        'expires_at',
        'status',
        'mode',
    ];

    protected $casts = [
        'user_id'    => 'integer',
        'product_id' => 'integer',
        'order_id'   => 'integer',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** The product this grants access to — the exam itself. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }

    /** The exam settings beside it, for the access window and target score. */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'product_id', 'product_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaperAttempt::class, 'enrolment_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CaseAnswer::class, 'enrolment_id');
    }

    /**
     * Expired means the window has closed, whatever the column says.
     *
     * The status column is written by the repository and can lag — nothing sweeps it on a
     * shared host, because the scheduler cannot be assumed there. So the date is the authority
     * and the column is the cache, never the other way round.
     */
    public function hasExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Usable means the candidate may still open a paper and write in it. */
    public function isUsable(): bool
    {
        return ! $this->hasExpired() && $this->status !== self::STATUS_COMPLETE;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** The newest enrolment is the current one. There is no "current" flag to disagree with. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
