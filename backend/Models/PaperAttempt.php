<?php

namespace Theme\Backend\Models;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sitting of one paper, and the record of exactly what was served.
 *
 * This is the table that makes a finished exam immutable. Editing a case afterwards changes
 * the case; it does not change what anybody sat.
 */
class PaperAttempt extends Model
{
    protected $table = 'lumen_attempts';

    protected $fillable = [
        'enrolment_id',
        'paper_id',
        'case_ids',
        'case_snapshot',
        'case_set_version',
        'seconds_remaining',
        'seconds_spent',
        'visited_case_ids',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'enrolment_id'      => 'integer',
        'paper_id'          => 'integer',
        'case_ids'          => 'array',
        'case_snapshot'     => 'array',
        'visited_case_ids'  => 'array',
        'case_set_version'  => 'integer',
        'seconds_remaining' => 'integer',
        'seconds_spent'     => 'integer',
        'started_at'        => 'datetime',
        'ended_at'          => 'datetime',
    ];

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class, 'enrolment_id');
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'paper_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CaseAnswer::class, 'attempt_id');
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * Freeze the cases as served.
     *
     * **The model answer is not in the snapshot, and this is the one defect from the source
     * deliberately not carried across.** There, the snapshot carries each case's model answer
     * and the attempt is returned in the same response that serves the case list — so a
     * candidate who opens the network tab can read the answers while still writing their
     * report. The source documents this against itself.
     *
     * The fix belongs here, at the point the snapshot is built, rather than on a model's
     * `$hidden` list. Hiding a field guards one serialisation path and not the next one
     * somebody writes; a value that was never stored cannot leak through any of them. When a
     * paper ends, the model answers are read from `lumen_cases` — live, by id — which is both
     * simpler and the only moment the candidate is entitled to them.
     *
     * Image ids rather than URLs: a private asset's URL is a signed link with a short life, so
     * a URL frozen here would be dead long before anybody replayed the sitting.
     *
     * @param  Collection<int, ExamCase>  $cases
     * @return array<int, array<string, mixed>>
     */
    public static function buildSnapshot(Collection $cases): array
    {
        return $cases->map(function (ExamCase $case) {
            return [
                'id'          => (int) $case->id,
                'title'       => $case->getTranslations('title'),
                'brief'       => $case->getTranslations('brief'),
                'instruction' => $case->getTranslations('instruction'),
                'score_max'   => (float) $case->score_max,
                'image_ids'   => $case->relationLoaded('images')
                    ? $case->images->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : Asset::query()
                        ->where('assetable_type', ExamCase::class)
                        ->where('assetable_id', $case->id)
                        ->where('usage', ExamCase::IMAGE_USAGE)
                        ->orderBy('order')->orderBy('id')
                        ->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                // Deliberately no `model_answer`. See the docblock above before adding one.
            ];
        })->values()->all();
    }
}
