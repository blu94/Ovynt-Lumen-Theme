<?php

namespace Theme\Backend\Models;

use App\Models\Asset;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * One case: a set of images, a clinical brief, and the instruction to report it.
 *
 * Named `ExamCase` rather than `Case` because `case` is a reserved word in PHP and
 * `class Case {}` will not parse. The module type is `exam-cases` for the same reason —
 * `ModuleRepositoryResolver` derives the class name from the module type, so a module called
 * `cases` would demand a class nobody can declare.
 */
class ExamCase extends Model
{
    use SoftDeletes;
    use HasTranslations;
    use LogsSystemActivity;

    protected $table = 'lumen_cases';

    public $translatable = ['title', 'brief', 'instruction', 'model_answer'];

    protected $fillable = [
        'paper_id',
        'title',
        'brief',
        'instruction',
        'model_answer',
        'score_max',
        'status',
        'orders',
    ];

    protected $casts = [
        'paper_id'  => 'integer',
        'score_max' => 'decimal:2',
        'orders'    => 'integer',
    ];

    /**
     * The usage label the case's images carry in the media library, so an operator can filter
     * to them and so a query for "this case's images" does not depend on nothing else ever
     * attaching to a case.
     */
    public const IMAGE_USAGE = 'LUMEN_CASE_IMAGE';

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'paper_id');
    }

    /**
     * The case's images.
     *
     * **Core's `AssetRepository` cannot attach these, and the reason is worth knowing.** Every
     * path in it that sets the morph type hard-codes `'App\Models\' . class_basename($model)`
     * — `AssetRepository.php:133`, `:139`, `:213`, `:230` — so attaching through it would write
     * `App\Models\ExamCase`, a class that does not exist, and this relation would never resolve.
     * There is no morph map to register instead, because a theme has no service provider in
     * which to register one.
     *
     * So `ExamCaseRepository` writes `assetable_type` and `assetable_id` itself, with the real
     * class name. Reading is ordinary Eloquent: this relation matches on the string that was
     * stored. Recorded as a core defect — any theme-owned model with media meets it.
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Asset::class, 'assetable')
            ->where('usage', self::IMAGE_USAGE)
            ->orderBy('order')
            ->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('orders')->orderBy('id');
    }
}
