<?php

namespace Theme\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Theme\Backend\Models\Exam;
use Theme\Backend\Support\ResolvesListFilters;

/**
 * The Exams screen's server side.
 *
 * The contract every theme-shipped module implements, because it is what
 * `GenericModuleController` calls: `baseIndexQuery`, `find`, `create`, `update`, `delete`,
 * `getOptions`. No interface and no service-provider binding — `ModuleRepositoryResolver`
 * resolves `Theme\Backend\Repositories\{Name}Repository` by name and constructs it through the
 * container, and a theme has no provider in which to bind anything anyway.
 */
class ExamRepository
{
    use ResolvesListFilters;

    public function baseIndexQuery(array $filters = [])
    {
        $query = Exam::query()->ordered()->withCount('papers');

        if (($status = $this->scalarFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function ($q) use ($term) {
                // Titles are locale-keyed JSON, so a LIKE across the column is the only search
                // that finds an exam by name in any locale without knowing which.
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    public function find($id)
    {
        return Exam::query()->withCount('papers')->findOrFail($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            return Exam::create($this->normalise($data));
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $exam = Exam::findOrFail($id);
            $exam->update($this->normalise($data, $exam));

            return $exam->fresh();
        });
    }

    /**
     * Soft delete, and refuse once anybody has sat it.
     *
     * The source refuses deletion once an exam has been *purchased*. Enrolment is the better
     * test and a wider one: it covers a staff grant and a free exam, neither of which has an
     * order behind it, and it is the thing that would actually be destroyed — a candidate's
     * papers, attempts and answers all cascade from the exam row.
     */
    public function delete($id)
    {
        $exam = Exam::withCount('enrolments')->findOrFail($id);

        if ($exam->enrolments_count > 0) {
            abort(403, 'Candidates have already sat this exam, so it cannot be deleted. Set its status to Inactive instead — that removes it from the catalogue and leaves every past sitting intact.');
        }

        $exam->delete();

        return true;
    }

    public function getOptions(array $columns = [])
    {
        return [
            'status' => [
                ['title' => 'Draft',    'value' => 'draft'],
                ['title' => 'Active',   'value' => 'active'],
                ['title' => 'Inactive', 'value' => 'inactive'],
            ],
            // Feeds the parent picker on the Papers form.
            'exams' => Exam::query()->ordered()->get()->map(fn (Exam $e) => [
                'title' => $e->getTranslation('title', app()->getLocale(), false) ?: $e->slug,
                'value' => $e->id,
            ])->all(),
        ];
    }

    /**
     * An allow-list, so a hand-crafted POST cannot write a column the form does not show.
     *
     * The cost of this shape is that a NEW column is silently dropped until it is added here —
     * the form saves, redraws without the value, and nothing reports a failure. Saffron lost a
     * whole per-branch delivery feature to exactly this. When adding a field, this list is the
     * second half of the change.
     */
    protected function normalise(array $data, ?Exam $existing = null): array
    {
        $out = collect($data)->only([
            'title', 'slug', 'subtitle', 'description', 'product_id',
            'duration_minutes', 'access_days', 'ideal_percent', 'status', 'orders',
        ])->all();

        // An empty product means free, and an empty string from a cleared picker means the
        // same thing as a null — otherwise "free" would depend on how the field was cleared.
        if (array_key_exists('product_id', $out)) {
            $out['product_id'] = ($out['product_id'] === '' || $out['product_id'] === null)
                ? null
                : (int) $out['product_id'];
        }

        $out['slug'] = $this->uniqueSlug(
            $out['slug'] ?? null,
            $out['title'] ?? ($existing?->getTranslations('title') ?? []),
            $existing
        );

        return $out;
    }

    protected function uniqueSlug(?string $slug, $title, ?Exam $existing): string
    {
        $name = is_array($title)
            ? ($title[app()->getLocale()] ?? (count($title) ? reset($title) : ''))
            : (string) $title;

        $base = Str::slug($slug ?: $name) ?: 'exam';
        $try  = $base;
        $n    = 2;

        while (Exam::withTrashed()
            ->where('slug', $try)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists()) {
            $try = $base . '-' . $n++;
        }

        return $try;
    }
}
