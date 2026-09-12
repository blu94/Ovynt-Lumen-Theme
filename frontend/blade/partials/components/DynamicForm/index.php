<?php

namespace Theme\Components;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Theme\Backend\Support\CurrentCandidate;

/**
 * Any form the Forms module built, rendered wherever a template asks for it.
 *
 * Ported from Saffron's component of the same name, which is itself Ella's: the schema comes
 * from `GET /api/storefront/forms/{slug}`, the answers go to `POST /api/storefront/forms/{slug}/submit`,
 * and every lead lands where the same form on any other page would. Two things are this theme's:
 *
 * - **Prefill.** `$data['prefill']` is a map of slugged field labels to values — `exam`, `paper`,
 *   `case` from a case-report link — and a signed-in candidate's `name` and `email` are added
 *   from their account, so a report is never a stranger's message. Keys in `$data['lock']`
 *   render read-only; the rest are a head start the candidate may change.
 * - **Checkboxes.** The Forms module offers them and the earlier ports did not draw them. A
 *   checked set is posted as one comma-separated string, because core keeps only scalar
 *   values on a submission.
 *
 * A slugged label is what core matches a submitted key against when no field id matches
 * (`FormController::submittedKeyFor()`), so the same rule names a field here and there.
 */
class DynamicForm
{
    /** Per-request render counter — deterministic uids, so ETag revalidation can match. */
    private static int $uidSequence = 0;

    public function render(array $data, string $locale, string $themeViewPath): string
    {
        $slug = trim((string) ($data['slug'] ?? ''));

        if ($slug === '') {
            return '';
        }

        $prefill = [];

        foreach ((array) ($data['prefill'] ?? []) as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $prefill[Str::slug((string) $key, '_')] = mb_substr(trim((string) $value), 0, 255);
            }
        }

        // The account's own name and email, when there is one. A head start, not a lock: a
        // candidate may want a reply somewhere other than the address they signed up with.
        if (filter_var($data['identify'] ?? true, FILTER_VALIDATE_BOOLEAN) && ($candidate = CurrentCandidate::get())) {
            $prefill += array_filter([
                'name'  => trim((string) ($candidate->name ?? '')),
                'email' => trim((string) ($candidate->email ?? '')),
            ]);
        }

        $locked = array_values(array_filter(
            array_map(fn ($key) => Str::slug((string) $key, '_'), (array) ($data['lock'] ?? [])),
            fn ($key) => $key !== '' && array_key_exists($key, $prefill)
        ));

        // One variable for `@json()`: Blade splits the directive's argument on top-level commas,
        // so an inline array with several keys is compiled into json_encode($a, $b, $c).
        $payload = [
            'slug'      => $slug,
            'locale'    => $locale,
            'showTitle' => filter_var($data['show_title'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'prefill'   => (object) $prefill,
            'locked'    => $locked,
            'labels'    => [
                'loading'    => __('Loading…'),
                'loadFailed' => __('This form could not be loaded just now.'),
                'submit'     => __('Send'),
                'submitting' => __('Sending…'),
                'success'    => __('Thank you — we have received your message.'),
                'failed'     => __('That could not be sent. Please try again.'),
                'choose'     => __('Choose…'),
            ],
        ];

        return View::make($themeViewPath, [
            'uid'     => 'lumen-form-' . Str::slug($slug) . '-' . ++self::$uidSequence,
            'intro'   => trim((string) ($data['intro'] ?? '')),
            'payload' => $payload,
        ])->render();
    }
}
