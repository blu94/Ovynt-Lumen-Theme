<?php

namespace Theme\Backend\Handlers;

use Illuminate\Database\Eloquent\Model;
use Theme\Backend\Models\Exam;

/**
 * The Exam tab on a product, read and written.
 *
 * Core calls this from {@see \App\Services\Module\ModuleExtensionRegistry}: the contributed keys
 * are pulled out of the validated payload **before** the Products repository sees them, so a
 * contributed key can never mass-assign a core column, and they are handed here instead.
 *
 * The registry refuses the whole contribution — logged, not partially applied — if a key is not
 * in `owns`, if an `owns` key already exists on the base module, or if this class sits outside
 * the theme's own namespace. None of that is defended against here; it is core's job and it has
 * already happened by the time either method runs.
 */
class ExamContributions
{
    /**
     * What the Exam tab shows when the product form opens.
     *
     * Defaults matter here: a product that has never been an exam has no row, and the form must
     * still draw sensible numbers rather than empty boxes an operator has to guess at. They match
     * the column defaults in the migration.
     */
    public function load(Model $record): array
    {
        $exam = Exam::query()->where('product_id', $record->getKey())->first();

        return [
            'is_exam'               => (bool) ($exam->is_exam ?? false),
            'access_days'           => (int) ($exam->access_days ?? 30),
            'ideal_percent'         => (int) ($exam->ideal_percent ?? 60),
            'exam_duration_minutes' => $exam->duration_minutes ?? null,
        ];
    }

    /**
     * Write the tab back.
     *
     * **A partial save must not silently switch an exam off.** The registry hands over only the
     * keys the request carried, so a save from a screen that never rendered this tab — a status
     * toggle from the product list, say — arrives with no `is_exam` at all. Treating that as
     * `false` would un-sell every exam in the shop the first time somebody edited a product from
     * the list. So the switch is only written when the payload actually contains it; the same
     * guard Saffron learned to put on its own repeaters, for the same reason.
     *
     * Nothing is deleted when an exam is switched off. The papers, the cases and every sitting
     * anybody has taken belong to the product and survive; switching it back on restores the
     * exam exactly as it was. That is why this is a flag and not the presence of a row.
     */
    public function save(Model $record, array $values): void
    {
        $productId = (int) $record->getKey();

        if ($productId <= 0) {
            return;
        }

        $exam = Exam::query()->firstOrNew(['product_id' => $productId]);

        if (array_key_exists('is_exam', $values)) {
            $exam->is_exam = (bool) $values['is_exam'];
        }

        // The numbers are clamped rather than trusted. They are validated in the schema too, but
        // the schema guards the form and this guards the column — a crafted request meets both.
        if (array_key_exists('access_days', $values)) {
            $exam->access_days = $this->clamp($values['access_days'], 1, 3650, 30);
        }

        if (array_key_exists('ideal_percent', $values)) {
            $exam->ideal_percent = $this->clamp($values['ideal_percent'], 1, 100, 60);
        }

        if (array_key_exists('exam_duration_minutes', $values)) {
            $raw = $values['exam_duration_minutes'];

            $exam->duration_minutes = ($raw === null || $raw === '')
                ? null
                : $this->clamp($raw, 1, 1440, 60);
        }

        // A row is written even when the switch is off, so an operator who configures the window
        // first and enables the exam second does not lose what they typed.
        $exam->save();
    }

    private function clamp($value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }
}
