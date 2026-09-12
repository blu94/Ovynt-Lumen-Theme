<?php

namespace Theme\Sections\General;

use Illuminate\Support\Facades\View;
use Theme\Backend\Support\TranslatesSectionData;

/**
 * A form from the Forms module, placed on a page.
 *
 * The form itself — its fields, validation, lead storage and the email to whoever is on
 * notify — is core's. This block is the heading around it and the two things a page knows
 * that a form does not:
 *
 * - **who is asking.** A signed-in candidate's name and email are filled in for them, by the
 *   DynamicForm component, so a case report is not a stranger's message.
 * - **what it is about.** A case report is raised from inside a sitting, and the link that
 *   opens it carries `?exam=…&paper=…&case=…`. Those are read here and filled into fields of
 *   the same name, locked, so the report arrives attached to the study it was raised against
 *   rather than described from memory. Any other form ignores them, because it has no such
 *   fields.
 *
 * Nothing here is trusted beyond that: the values are prefilled text, and core validates the
 * submission against the form's own declared fields as it does for every lead.
 */
class InquiryForm
{
    use TranslatesSectionData;

    /** Query parameters a case-report link may carry, and the form field each fills. */
    private const CONTEXT_KEYS = ['exam', 'paper', 'case'];

    public function render(?array $data, string $locale, string $themeViewPath): string
    {
        $data = $data ?? [];

        // The autocomplete a later version may use stores an object; a text field stores the
        // bare slug. Both shapes are read, as Saffron's Newsletter does.
        $raw  = $data['form_slug'] ?? '';
        $slug = is_array($raw) ? (string) ($raw['value'] ?? $raw['slug'] ?? '') : (string) $raw;
        $slug = trim($slug);

        $prefill = [];

        foreach (self::CONTEXT_KEYS as $key) {
            $value = request()->query($key);

            if (is_string($value) && trim($value) !== '') {
                $prefill[$key] = mb_substr(trim($value), 0, 160);
            }
        }

        return View::make($themeViewPath, [
            'slug'      => $slug,
            'heading'   => $this->translate($data['heading'] ?? null, $locale),
            'intro'     => $this->translate($data['intro'] ?? null, $locale),
            'showTitle' => filter_var($data['show_form_title'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'prefill'   => $prefill,
            'locked'    => array_keys($prefill),
        ])->render();
    }
}
