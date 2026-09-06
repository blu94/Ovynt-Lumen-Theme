<?php

namespace Theme\Backend\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Models\PaperAttempt;

/**
 * An exam's papers, authored on the product's Exam tab.
 *
 * A paper used to be its own sidebar module. It is a handful of rows with five fields, belonging
 * to exactly one exam — which is what a repeater is for, and the same seam Saffron already
 * contributes a repeater through (`modifier_groups`). Moving it here removed a top-level module
 * and put the papers where the exam is.
 *
 * **Cases nest inside each paper**, two levels deep on the product form. That mirrors the
 * system this theme is modelled on, where `PackagesDialog.vue` renders `QuestionsTable`
 * inside itself, so an exam, its papers and their cases are one screen. See ExamCases.
 */
class ExamPapers
{
    public function load(Model $record): array
    {
        $papers = ExamPaper::query()
            ->where('product_id', $record->getKey())
            ->withCount(['cases' => fn ($q) => $q->where('status', 'active')])
            ->ordered()
            ->get();

        $cases = new ExamCases();

        return [
            'papers' => $papers->map(fn (ExamPaper $p) => [
                // The id is what makes an edit an edit. Without it every save would delete the
                // paper and create a new one, and its cases — which hang off the id — would be
                // orphaned from a row that no longer exists.
                'id'               => $p->id,
                'title'            => $p->getTranslations('title'),
                'description'      => $p->getTranslations('description'),
                'duration_minutes' => (int) $p->duration_minutes,
                'case_set_version' => (int) $p->case_set_version,
                'status'           => $p->status,
                'cases'            => $cases->load($p),
            ])->values()->all(),
        ];
    }

    /**
     * Write the papers back.
     *
     * Guarded like every other contributed repeater in this codebase: a save that never rendered
     * the Exam tab arrives with no `papers` key at all, and reading that as "this exam has no
     * papers" would delete every one of them the first time somebody edited a product from the
     * list. Saffron lost a branch's whole dining room to exactly this shape.
     */
    public function save(Model $record, array $values): void
    {
        if (! array_key_exists('papers', $values) || ! is_array($values['papers'])) {
            return;
        }

        $productId = (int) $record->getKey();

        if ($productId <= 0) {
            return;
        }

        $keptIds = [];

        foreach (array_values($values['papers']) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = $row['title'] ?? [];

            // A row the operator opened and abandoned submits empty. An unnamed paper would
            // reach a candidate's list as a blank line they could open.
            $plain = is_array($title)
                ? trim((string) ($title[app()->getLocale()] ?? ($title['en'] ?? (count($title) ? reset($title) : ''))))
                : trim((string) $title);

            if ($plain === '') {
                continue;
            }

            $existingId = ! empty($row['id']) ? (int) $row['id'] : null;

            $paper = $existingId
                ? ExamPaper::query()->where('product_id', $productId)->whereKey($existingId)->first()
                : null;

            $payload = [
                'product_id'       => $productId,
                'title'            => $title,
                'description'      => $row['description'] ?? null,
                'duration_minutes' => max(1, min(1440, (int) ($row['duration_minutes'] ?? 60))),
                'case_set_version' => max(1, (int) ($row['case_set_version'] ?? 1)),
                'status'           => in_array($row['status'] ?? 'active', ['active', 'inactive'], true)
                    ? $row['status']
                    : 'active',
                // The drag order is the order candidates meet the papers in.
                'orders'           => $index,
            ];

            if ($paper) {
                $paper->update($payload);
            } else {
                $payload['slug'] = $this->uniqueSlug($plain, $productId);
                $paper = ExamPaper::create($payload);
            }

            // The nested repeater, written once the paper has an id to hang off. Guarded the
            // same way: a row whose dialog was never opened carries no `cases` key, and reading
            // that as "this paper has none" would empty it.
            if (array_key_exists('cases', $row)) {
                (new ExamCases())->save($paper, is_array($row['cases']) ? $row['cases'] : []);
            }

            $keptIds[] = $paper->id;
        }

        $this->removeDropped($productId, $keptIds);
    }

    /**
     * Papers the form no longer lists.
     *
     * **A paper that has been sat is never removed, whatever the form says.** An attempt is the
     * record of what a candidate was served, and it hangs off this row; deleting the paper would
     * take that with it. The old Papers module refused the delete outright with a message. A
     * repeater has nowhere to put a message, so the row is kept and set Inactive instead — the
     * same outcome the refusal was protecting, reached without a dialog nobody would see.
     *
     * Everything else is soft-deleted, so its cases survive and restoring the row restores them.
     */
    protected function removeDropped(int $productId, array $keptIds): void
    {
        $dropped = ExamPaper::query()
            ->where('product_id', $productId)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->get();

        foreach ($dropped as $paper) {
            $satIt = PaperAttempt::query()->where('paper_id', $paper->id)->exists();

            if ($satIt) {
                if ($paper->status !== 'inactive') {
                    $paper->update(['status' => 'inactive']);

                    try {
                        activity()
                            ->performedOn($paper)
                            ->withProperties([
                                'note' => 'Removed from the product form, but candidates have sat it — kept and set Inactive so their attempts and answers survive.',
                            ])
                            ->log('Kept a sat paper');
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                continue;
            }

            $paper->delete();
        }
    }

    /** Unique within its exam, not globally: every exam is allowed a "Paper 1". */
    protected function uniqueSlug(string $name, int $productId): string
    {
        $base = Str::slug($name) ?: 'paper';
        $try  = $base;
        $n    = 2;

        while (ExamPaper::withTrashed()->where('product_id', $productId)->where('slug', $try)->exists()) {
            $try = $base . '-' . $n++;
        }

        return $try;
    }
}
