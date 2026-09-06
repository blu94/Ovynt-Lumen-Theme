<?php

namespace Theme\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Product;
use Theme\Backend\Models\Exam;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Support\ResolvesListFilters;

/**
 * The Papers screen's server side.
 *
 * A paper belongs to exactly one exam and is opened from the exam's own screen, so this list
 * is filterable by exam and the form carries the parent as a picker.
 */
class ExamPaperRepository
{
    use ResolvesListFilters;

    public function baseIndexQuery(array $filters = [])
    {
        $query = ExamPaper::query()
            ->with(['product:id,title,slug'])
            ->withCount('cases')
            ->ordered();

        if (($status = $this->scalarFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        // How the Exams screen links through to "the papers of this exam".
        if (($productId = $this->idFilter($filters, 'product_id')) !== null) {
            $query->where('product_id', $productId);
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    public function find($id)
    {
        return ExamPaper::query()
            ->with(['product:id,title,slug'])
            ->withCount('cases')
            ->findOrFail($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return ExamPaper::create($this->normalise($data));
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $paper = ExamPaper::findOrFail($id);

            $before = $paper->only(['duration_minutes']);
            $paper->update($this->normalise($data, $paper));

            /**
             * **Changing the clock does not reach a sitting already in progress.**
             *
             * The duration is copied onto an attempt when the paper is opened, so an edit here
             * governs future sittings only. That is deliberate — a candidate halfway through
             * must not have time taken away by an operator saving an unrelated field — but it
             * is invisible from the form, so it is logged where somebody investigating "why
             * does their timer say something else" will find it.
             */
            if ((int) ($before['duration_minutes'] ?? 0) !== (int) $paper->duration_minutes) {
                try {
                    activity()
                        ->performedOn($paper)
                        ->withProperties([
                            'from' => (int) ($before['duration_minutes'] ?? 0),
                            'to'   => (int) $paper->duration_minutes,
                            'note' => 'Applies to sittings started after this change; attempts already open keep the time they were given.',
                        ])
                        ->log('Changed paper duration');
                } catch (\Throwable $e) {
                    // An audit trail must never be the thing that stops an operator saving.
                    report($e);
                }
            }

            return $paper->fresh();
        });
    }

    /**
     * Soft delete, refused once the paper has been sat.
     *
     * Attempts cascade from this row, and an attempt is the record of what somebody was served.
     */
    public function delete($id)
    {
        $paper = ExamPaper::withCount('attempts')->findOrFail($id);

        if ($paper->attempts_count > 0) {
            abort(403, 'This paper has been sat, so it cannot be deleted. Set its status to Inactive instead — it leaves new sittings and every finished one still replays.');
        }

        $paper->delete();

        return true;
    }

    public function getOptions(array $columns = [])
    {
        return [
            'status' => [
                ['title' => 'Active',   'value' => 'active'],
                ['title' => 'Inactive', 'value' => 'inactive'],
            ],
            // The exams to choose a parent from: products carrying an exam row. Listed by the
            // PRODUCT's title, because that is the exam's title — there is no second name.
            'exams' => Product::query()
                ->whereIn('id', Exam::query()->isExam()->select('product_id'))
                ->orderBy('orders')->orderBy('id')
                ->get(['id', 'title', 'slug'])
                ->map(fn (Product $p) => [
                    'title' => $p->getTranslation('title', app()->getLocale(), false) ?: $p->slug,
                    'value' => $p->id,
                ])->all(),
            'papers' => ExamPaper::query()->with('product:id,title,slug')->ordered()->get()->map(function (ExamPaper $p) {
                $paper = $p->getTranslation('title', app()->getLocale(), false) ?: $p->slug;
                $exam  = $p->product?->getTranslation('title', app()->getLocale(), false);

                // Papers are named "Paper 1" at every exam, so the picker on the Cases form has
                // to say which exam's Paper 1 this is or it cannot be used.
                return [
                    'title' => $exam ? "{$exam} — {$paper}" : $paper,
                    'value' => $p->id,
                ];
            })->all(),
        ];
    }

    protected function normalise(array $data, ?ExamPaper $existing = null): array
    {
        $out = collect($data)->only([
            'product_id', 'title', 'slug', 'description',
            'duration_minutes', 'case_set_version', 'status', 'orders',
        ])->all();

        if (array_key_exists('product_id', $out)) {
            $out['product_id'] = (int) $out['product_id'];
        }

        $productId = $out['product_id'] ?? $existing?->product_id;

        $out['slug'] = $this->uniqueSlug(
            $out['slug'] ?? null,
            $out['title'] ?? ($existing?->getTranslations('title') ?? []),
            (int) $productId,
            $existing
        );

        return $out;
    }

    /**
     * Unique **within its exam**, not globally.
     *
     * Every exam wants a paper called "Paper 1", and the storefront reads a paper as
     * `/exam/{examSlug}/{paperSlug}` — so the pair is what has to be unique, which is what the
     * migration's compound index says too.
     */
    protected function uniqueSlug(?string $slug, $title, int $productId, ?ExamPaper $existing): string
    {
        $name = is_array($title)
            ? ($title[app()->getLocale()] ?? (count($title) ? reset($title) : ''))
            : (string) $title;

        $base = Str::slug($slug ?: $name) ?: 'paper';
        $try  = $base;
        $n    = 2;

        while (ExamPaper::withTrashed()
            ->where('product_id', $productId)
            ->where('slug', $try)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists()) {
            $try = $base . '-' . $n++;
        }

        return $try;
    }
}
