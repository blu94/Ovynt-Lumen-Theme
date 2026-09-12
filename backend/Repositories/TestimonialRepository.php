<?php

namespace Theme\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Theme\Backend\Models\Testimonial;
use Theme\Backend\Support\ResolvesListFilters;

/**
 * The Testimonials screen's server side.
 *
 * Same contract every theme-shipped module uses — `baseIndexQuery`, `find`, `create`, `update`,
 * `delete`, `getOptions` — called by core's `GenericModuleController`. Nothing here is reached
 * from the storefront; the section reads the model directly.
 */
class TestimonialRepository
{
    use ResolvesListFilters;

    public function baseIndexQuery(array $filters = [])
    {
        $query = Testimonial::query()->ordered();

        if (($status = $this->scalarFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function ($q) use ($term) {
                $q->where('author', 'like', "%{$term}%")
                    ->orWhere('role', 'like', "%{$term}%")
                    ->orWhere('quote', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    public function find($id)
    {
        return Testimonial::query()->findOrFail($id);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $testimonial = Testimonial::create($this->normalise($data));

            $this->log($testimonial, 'Added a testimonial');

            return $testimonial;
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $testimonial = Testimonial::findOrFail($id);
            $before      = $testimonial->status;

            $testimonial->update($this->normalise($data));

            if ($before !== $testimonial->status) {
                $this->log($testimonial, $testimonial->status === Testimonial::STATUS_PUBLISHED
                    ? 'Published a testimonial'
                    : 'Unpublished a testimonial');
            }

            return $testimonial->fresh();
        });
    }

    /**
     * Allowed — a testimonial is content, and unlike an enrolment it is nobody's history. Soft,
     * so a quote removed by mistake can be restored from the database.
     */
    public function delete($id)
    {
        return (bool) Testimonial::findOrFail($id)->delete();
    }

    public function getOptions(array $columns = [])
    {
        return [
            'status' => [
                ['title' => 'Draft',     'value' => Testimonial::STATUS_DRAFT],
                ['title' => 'Published', 'value' => Testimonial::STATUS_PUBLISHED],
            ],
            'rating' => collect(range(5, 1))
                ->map(fn ($n) => ['title' => "{$n} / 5", 'value' => $n])
                ->prepend(['title' => 'No rating', 'value' => 0])
                ->all(),
        ];
    }

    /**
     * The columns this module owns, and nothing else. The schema validates the values; this
     * guards the row, so a crafted request meets both.
     */
    private function normalise(array $data): array
    {
        $clean = collect($data)->only(['author', 'role', 'quote', 'rating', 'status', 'orders', 'lead_id'])->all();

        if (array_key_exists('rating', $clean)) {
            $rating          = $clean['rating'];
            $clean['rating'] = ($rating === null || $rating === '' || (int) $rating <= 0)
                ? null
                : max(1, min(5, (int) $rating));
        }

        if (array_key_exists('status', $clean)) {
            $clean['status'] = in_array($clean['status'], [Testimonial::STATUS_DRAFT, Testimonial::STATUS_PUBLISHED], true)
                ? $clean['status']
                : Testimonial::STATUS_DRAFT;
        }

        if (array_key_exists('lead_id', $clean)) {
            $clean['lead_id'] = is_numeric($clean['lead_id']) && (int) $clean['lead_id'] > 0 ? (int) $clean['lead_id'] : null;
        }

        return $clean;
    }

    protected function log(Testimonial $testimonial, string $what): void
    {
        try {
            activity()->performedOn($testimonial)->log($what);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
