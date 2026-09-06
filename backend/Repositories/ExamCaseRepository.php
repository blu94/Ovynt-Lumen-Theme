<?php

namespace Theme\Backend\Repositories;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Theme\Backend\Models\ExamCase;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Support\ResolvesListFilters;

/**
 * The Cases screen's server side.
 *
 * The only module in this theme that carries media, which is the one place a theme-owned model
 * cannot use core's helpers — see {@see attachImages()}.
 */
class ExamCaseRepository
{
    use ResolvesListFilters;

    public function baseIndexQuery(array $filters = [])
    {
        $query = ExamCase::query()
            ->with(['paper:id,product_id,title,slug', 'paper.product:id,title'])
            ->ordered();

        if (($status = $this->scalarFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        if (($paperId = $this->idFilter($filters, 'paper_id')) !== null) {
            $query->where('paper_id', $paperId);
        }

        // "Every case in this exam", across its papers — the filter an author actually wants
        // when reviewing a bank before an exam goes live.
        if (($productId = $this->idFilter($filters, 'product_id')) !== null) {
            $query->whereIn('paper_id', ExamPaper::query()->where('product_id', $productId)->pluck('id'));
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('brief', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    public function find($id)
    {
        $case = ExamCase::query()
            ->with(['paper:id,product_id,title,slug', 'paper.product:id,title', 'images'])
            ->findOrFail($id);

        // The form's image field reads `assets`, the same key core's own forms use, so the
        // control behaves identically here to everywhere else in the admin.
        $case->setAttribute('assets', $case->images->values());

        return $case;
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $case = ExamCase::create($this->normalise($data));

            $this->attachImages($case, $data);

            return $case->fresh();
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $case = ExamCase::findOrFail($id);
            $case->update($this->normalise($data, $case));

            // Guarded, and the guard is load-bearing: a partial save that never rendered the
            // image field — a status toggle from the list row — must not be read as "this case
            // has no images now". Saffron lost a branch's whole dining room to the same shape.
            if (array_key_exists('assets', $data)) {
                $this->attachImages($case, $data);
            }

            return $case->fresh();
        });
    }

    public function delete($id)
    {
        $case = ExamCase::findOrFail($id);

        /**
         * Soft delete, always, and never refused.
         *
         * Unlike an exam or a paper, a deleted case does not orphan anything: an attempt
         * replays from its own snapshot and its stored ids, and `CaseAnswer::examCase()`
         * resolves `withTrashed()`. So removing a case from the bank is safe even mid-window,
         * and the sittings that already served it are unaffected.
         *
         * The images are deliberately left attached. They stay in the media library pointing at
         * a trashed case, which is what makes restoring one put its pictures back.
         */
        $case->delete();

        return true;
    }

    public function getOptions(array $columns = [])
    {
        return [
            'status' => [
                ['title' => 'Active',   'value' => 'active'],
                ['title' => 'Inactive', 'value' => 'inactive'],
            ],
            // The paper picker. Papers are authored on the product's Exam tab and have no
            // repository of their own any more, so the list is built here — named
            // "{exam} — {paper}", because every exam has a "Paper 1" and the picker is
            // unusable without saying which one.
            'papers' => ExamPaper::query()
                ->with('product:id,title,slug')
                ->ordered()
                ->get()
                ->map(function (ExamPaper $p) {
                    $paper = $p->getTranslation('title', app()->getLocale(), false) ?: $p->slug;
                    $exam  = $p->product?->getTranslation('title', app()->getLocale(), false);

                    return [
                        'title' => $exam ? "{$exam} — {$paper}" : $paper,
                        'value' => $p->id,
                    ];
                })->all(),
        ];
    }

    /**
     * Attach the media library rows this case's images point at.
     *
     * **Core's `AssetRepository` cannot do this, and it is a core defect rather than a gap in
     * this theme.** Every path in it that sets the morph type hard-codes
     * `'App\Models\' . class_basename($model)` — `AssetRepository.php:133`, `:139`, `:213`,
     * `:230`. Attaching a theme-owned model through it writes `App\Models\ExamCase`, a class
     * that does not exist, so `ExamCase::images()` would never resolve and the images would be
     * invisible the moment the form was reopened. The usual escape — registering a morph alias
     * — needs a service provider, which a theme does not have.
     *
     * So the columns are written here with the real class name. `assetable_type` and
     * `assetable_id` are both on `Asset::$fillable`, and reading is ordinary Eloquent: the
     * morph matches on the string that was stored.
     *
     * A full replace, like every repeater in this codebase: the control posts the complete
     * list, so an image the operator removed is expressed by its absence, and an emptied field
     * detaches them all rather than requiring a second screen to do it.
     */
    protected function attachImages(ExamCase $case, array $data): void
    {
        $rows = $data['assets'] ?? [];

        if (! is_array($rows)) {
            return;
        }

        $ids = collect($rows)
            // The control posts `[{id: 4}, {id: 9}]`; a hand-crafted request might post `[4, 9]`.
            ->map(fn ($row) => is_array($row) ? ($row['id'] ?? null) : $row)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        // Only assets that exist. A stale option in a form left open while somebody emptied the
        // media library would otherwise point a case at nothing.
        $live = Asset::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        $ids  = $ids->intersect($live)->values();

        // Detach what this case no longer claims, rather than deleting the file: the asset is
        // still in the library and may be used elsewhere. Scoped to this case's own usage so a
        // future feature attaching something else to a case is not swept up by it.
        Asset::query()
            ->where('assetable_type', ExamCase::class)
            ->where('assetable_id', $case->id)
            ->where('usage', ExamCase::IMAGE_USAGE)
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ids))
            ->update(['assetable_id' => null, 'assetable_type' => null]);

        foreach ($ids as $index => $assetId) {
            Asset::query()->whereKey($assetId)->update([
                'assetable_type' => ExamCase::class,
                'assetable_id'   => $case->id,
                'usage'          => ExamCase::IMAGE_USAGE,
                // The order the operator arranged them in is the order they are read in, which
                // matters: a plain film and its lateral are a sequence, not a set.
                'order'          => $index,
            ]);
        }
    }

    protected function normalise(array $data, ?ExamCase $existing = null): array
    {
        $out = collect($data)->only([
            'paper_id', 'title', 'brief', 'instruction', 'model_answer',
            'score_max', 'status', 'orders',
        ])->all();

        if (array_key_exists('paper_id', $out)) {
            $out['paper_id'] = (int) $out['paper_id'];
        }

        if (array_key_exists('score_max', $out)) {
            // A case worth nothing cannot be marked, and a negative ceiling is not a thing.
            $out['score_max'] = max(0.5, round((float) $out['score_max'], 2));
        }

        return $out;
    }
}
