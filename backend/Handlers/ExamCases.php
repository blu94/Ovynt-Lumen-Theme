<?php

namespace Theme\Backend\Handlers;

use App\Models\Asset;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\ExamCase;
use Theme\Backend\Models\ExamPaper;

/**
 * A paper's cases, authored in the repeater nested inside the paper's own dialog.
 *
 * Two levels deep on the product form, which mirrors the system this theme is modelled on:
 * there, `PackagesDialog.vue` imports and renders `QuestionsTable` inside itself, so an exam,
 * its papers and their cases are one screen. Ovynt expresses the same shape declaratively —
 * a repeater's dialog renders through the generic `Builder`, so a differently-shaped repeater
 * nests inside one.
 */
class ExamCases
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function load(ExamPaper $paper): array
    {
        $cases = ExamCase::query()
            ->where('paper_id', $paper->id)
            ->with('images')
            ->ordered()
            ->get();

        return $cases->map(fn (ExamCase $c) => [
            // Without the id every save would delete the case and create a new one — and an
            // answer written against it points at the old id.
            'id'           => $c->id,
            'title'        => $c->getTranslations('title'),
            'brief'        => $c->getTranslations('brief'),
            'instruction'  => $c->getTranslations('instruction'),
            'model_answer' => $c->getTranslations('model_answer'),
            'score_max'    => (float) $c->score_max,
            'status'       => $c->status,
            // The image field reads `assets`, the key core's own forms use, so the control
            // behaves here exactly as it does everywhere else in the admin.
            'assets'       => $c->images->values(),
            'image_count'  => $c->images->count(),
        ])->values()->all();
    }

    /**
     * Write a paper's cases.
     *
     * Guarded like the papers repeater above it: a payload with no `cases` key never rendered
     * the dialog, and reading that as "this paper has no cases" would empty a paper the first
     * time somebody edited an unrelated field.
     */
    public function save(ExamPaper $paper, array $rows): void
    {
        if (! is_array($rows)) {
            return;
        }

        $keptIds = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = $row['title'] ?? [];

            $plain = is_array($title)
                ? trim((string) ($title[app()->getLocale()] ?? ($title['en'] ?? (count($title) ? reset($title) : ''))))
                : trim((string) $title);

            // A row the operator opened and abandoned. An unnamed case would reach a sitting as
            // a blank study nobody can report.
            if ($plain === '') {
                continue;
            }

            $existingId = ! empty($row['id']) ? (int) $row['id'] : null;

            $case = $existingId
                ? ExamCase::query()->where('paper_id', $paper->id)->whereKey($existingId)->first()
                : null;

            $payload = [
                'paper_id'     => $paper->id,
                'title'        => $title,
                'brief'        => $row['brief'] ?? null,
                'instruction'  => $row['instruction'] ?? null,
                'model_answer' => $row['model_answer'] ?? null,
                // A case worth nothing cannot be marked, and a negative ceiling is not a thing.
                'score_max'    => max(0.5, round((float) ($row['score_max'] ?? 5), 2)),
                'status'       => in_array($row['status'] ?? 'active', ['active', 'inactive'], true)
                    ? $row['status']
                    : 'active',
                'orders'       => $index,
            ];

            $case = $case ? tap($case)->update($payload) : ExamCase::create($payload);

            // Guarded separately: the image field may legitimately be absent from a row the
            // operator never opened, and an absent field must not detach a case's pictures.
            if (array_key_exists('assets', $row)) {
                $this->attachImages($case, $row['assets']);
            }

            $keptIds[] = $case->id;
        }

        $this->removeDropped($paper, $keptIds);
    }

    /**
     * Cases the form no longer lists.
     *
     * **A case somebody has answered is never removed.** Unlike a paper, whose attempt hangs off
     * its row, a case's evidence is the answer written against it — so the row is kept and set
     * Inactive, which takes it out of new sittings while leaving every report and mark intact.
     * Anything unanswered is soft-deleted, and a finished sitting still replays it from the
     * attempt's own frozen snapshot either way.
     */
    protected function removeDropped(ExamPaper $paper, array $keptIds): void
    {
        $dropped = ExamCase::query()
            ->where('paper_id', $paper->id)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->get();

        foreach ($dropped as $case) {
            $answered = CaseAnswer::query()->where('case_id', $case->id)->exists();

            if ($answered) {
                if ($case->status !== 'inactive') {
                    $case->update(['status' => 'inactive']);

                    try {
                        activity()
                            ->performedOn($case)
                            ->withProperties([
                                'note' => 'Removed from the paper, but candidates have answered it — kept and set Inactive so their reports and marks survive.',
                            ])
                            ->log('Kept an answered case');
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                continue;
            }

            $case->delete();
        }
    }

    /**
     * Attach the media library rows this case's images point at.
     *
     * **Core's `AssetRepository` cannot do this** — every path in it that sets the morph type
     * hard-codes `'App\Models\' . class_basename($model)`, so attaching a theme-owned model
     * through it writes a class that does not exist and the relation never resolves. Recorded
     * as `ISSUES-CORE.md` C6. The columns are written here with the real class name instead;
     * both are on `Asset::$fillable`, and reading is ordinary Eloquent.
     */
    protected function attachImages(ExamCase $case, $rows): void
    {
        if (! is_array($rows)) {
            return;
        }

        $ids = collect($rows)
            // The control posts `[{id: 4}, {id: 9}]`; a crafted request might post `[4, 9]`.
            ->map(fn ($row) => is_array($row) ? ($row['id'] ?? null) : $row)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        // Only assets that exist — a stale option in a form left open while somebody emptied
        // the media library would otherwise point a case at nothing.
        $live = Asset::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        $ids  = $ids->intersect($live)->values();

        // Detach what the case no longer claims rather than deleting the file: the asset stays
        // in the library and may be used elsewhere.
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
                // The order the operator arranged them in is the order they are read in: a
                // plain film and its lateral are a sequence, not a set.
                'order'          => $index,
            ]);
        }
    }
}
