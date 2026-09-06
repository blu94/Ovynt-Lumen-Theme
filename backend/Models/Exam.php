<?php

namespace Theme\Backend\Models;

use App\Models\Product;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a product needs in order to *be* an exam.
 *
 * **This is not the exam.** The product is the exam — it carries the title, slug, description,
 * price, tax and catalogue presence, and it is the row a candidate buys. This holds only the
 * handful of facts core's Products module has no concept of, contributed through
 * `admin/extends/products.json` and written by `Theme\Backend\Handlers\ExamContributions`.
 *
 * One row per product, and `is_exam` rather than mere presence decides whether the product is
 * one, so switching it off does not discard the access window an operator configured.
 */
class Exam extends Model
{
    use LogsSystemActivity;

    protected $table = 'lumen_exams';

    protected $fillable = [
        'product_id',
        'is_exam',
        'access_days',
        'ideal_percent',
        'duration_minutes',
    ];

    protected $casts = [
        'product_id'       => 'integer',
        'is_exam'          => 'boolean',
        'access_days'      => 'integer',
        'ideal_percent'    => 'integer',
        'duration_minutes' => 'integer',
    ];

    /**
     * A relation, deliberately, even though there is no database foreign key.
     *
     * Eloquent does not need one, and a constraint into `products` would make uninstalling this
     * theme fail on any shop that still has products. See the migration.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function papers(): HasMany
    {
        return $this->hasMany(ExamPaper::class, 'product_id', 'product_id');
    }

    public function activePapers(): HasMany
    {
        return $this->papers()->where('status', 'active')->orderBy('orders')->orderBy('id');
    }

    public function scopeIsExam(Builder $query): Builder
    {
        return $query->where('is_exam', true);
    }

    /**
     * The product ids that are exams and are on sale.
     *
     * One query, used by the catalogue and by anything else that needs "which products are
     * exams" without loading them.
     *
     * @return array<int,int>
     */
    public static function sellableProductIds(): array
    {
        return static::query()
            ->isExam()
            ->whereIn('product_id', Product::query()->where('status', 'active')->select('id'))
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** An exam nothing charges for is free — the product's own price decides, never this row. */
    public function isFree(): bool
    {
        return (float) ($this->product?->price ?? 0) <= 0;
    }

    /** The highest total a candidate could score across the cases they are actually served. */
    public function maxScore(): float
    {
        return (float) ExamCase::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('paper_id', $this->activePapers()->select('id'))
            ->sum('score_max');
    }
}
