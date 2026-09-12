<?php

namespace Theme\Backend\Storefront\Actions;

use App\Contracts\Storefront\ActionHandler;
use App\Models\Asset;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Theme\Backend\Models\CaseAnswer;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\ExamCase;
use Theme\Backend\Models\ExamPaper;
use Theme\Backend\Models\PaperAttempt;

/**
 * What the six player actions share: who may call them, and how a sitting is found and shown.
 *
 * Every action is a signed-in candidate's, so the audience is fixed here rather than declared
 * six times. Core resolves the candidate through `CustomerToken::user()` — the C7 fix — so the
 * `$customer` a handler receives is the same person the Exam Papers page was rendered for.
 *
 * **Every refusal a candidate can act on is a `ValidationException`**, which core answers as a
 * 422 the page can show. The two sentences that matter most are deliberately the same ones the
 * Exam Papers block uses: "you do not have access" is given for an exam that does not exist,
 * one the candidate does not hold, and a sitting that is somebody else's, so a stranger
 * probing ids learns nothing about what exists.
 *
 * Handlers run inside the transaction core opened; none opens its own. Where two requests can
 * race — opening a paper twice, two progress pings crossing — the row is locked here first.
 */
abstract class CandidateAction implements ActionHandler
{
    public function audience(): string
    {
        return self::CUSTOMER;
    }

    // ── refusals ────────────────────────────────────────────────────────────────

    /** @return never */
    protected function refuse(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ── finding things the candidate holds ──────────────────────────────────────

    /**
     * The candidate's current enrolment for a product, or a refusal.
     *
     * Newest first, because a lapsed candidate who bought again has two rows and only the later
     * one describes their access now. An expired window refuses every write: what they wrote
     * is kept, and buying again starts a fresh enrolment rather than reopening this one.
     */
    protected function enrolmentFor(User $customer, int $productId, bool $lock = false): Enrolment
    {
        $query = Enrolment::query()->forUser($customer->id)->where('product_id', $productId)->newestFirst();

        $enrolment = $lock ? $query->lockForUpdate()->first() : $query->first();

        if ($enrolment === null) {
            $this->refuse('exam', __('You do not have access to this exam.'));
        }

        if ($enrolment->hasExpired()) {
            $this->refuse('exam', __('Your access to this exam has ended.'));
        }

        return $enrolment;
    }

    /**
     * A sitting the candidate owns, with its enrolment and paper, or a refusal.
     *
     * Somebody else's attempt id gets the same answer as an id that does not exist.
     */
    protected function ownedAttempt(User $customer, int $attemptId, bool $lock = false): PaperAttempt
    {
        $query = PaperAttempt::query()->with(['enrolment', 'paper' => fn ($q) => $q->withTrashed()])->whereKey($attemptId);

        $attempt = $lock ? $query->lockForUpdate()->first() : $query->first();

        if ($attempt === null || $attempt->enrolment === null || (int) $attempt->enrolment->user_id !== (int) $customer->id) {
            $this->refuse('attempt', __('You do not have access to this exam.'));
        }

        if ($attempt->enrolment->hasExpired()) {
            $this->refuse('attempt', __('Your access to this exam has ended.'));
        }

        return $attempt;
    }

    // ── theme settings ──────────────────────────────────────────────────────────

    /**
     * The theme's saved settings, read the way the storefront renderer reads them.
     *
     * An action runs on an API route, where nothing has booted the theme, so `View::shared()`
     * holds nothing. The published config file is the same source `ThemeController::bootTheme()`
     * uses; read directly rather than through its cache key so a setting saved a moment ago is
     * honoured by the next ping.
     */
    protected function setting(string $key, mixed $default = null): mixed
    {
        static $settings = null;

        if ($settings === null) {
            $path = Theme::activeConfigReadPath();
            $config = File::exists($path) ? json_decode((string) File::get($path), true) : null;

            $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];
        }

        return $settings[$key] ?? $default;
    }

    // ── payloads ────────────────────────────────────────────────────────────────

    /**
     * The sitting as the player needs it. The clock is the attempt's own, frozen when it was
     * opened; the paper's current duration is deliberately not consulted.
     */
    protected function attemptPayload(PaperAttempt $attempt, Enrolment $enrolment, ?ExamPaper $paper, string $locale): array
    {
        return [
            'id'                => (int) $attempt->id,
            'paper'             => $paper ? [
                'slug'    => $paper->slug,
                'title'   => $paper->getTranslation('title', $locale, true) ?: $paper->slug,
                'minutes' => (int) $paper->duration_minutes,
            ] : null,
            'mode'              => $enrolment->mode ?: Enrolment::MODE_PRACTICE,
            'seconds_remaining' => (int) $attempt->seconds_remaining,
            'seconds_spent'     => (int) $attempt->seconds_spent,
            'visited_case_ids'  => array_values(array_map('intval', (array) ($attempt->visited_case_ids ?? []))),
            'started_at'        => $attempt->started_at?->toIso8601String(),
            'ended_at'          => $attempt->ended_at?->toIso8601String(),
            'expires_at'        => $enrolment->expires_at?->toIso8601String(),
        ];
    }

    /**
     * The cases exactly as this sitting was served them — from the snapshot, never the live
     * rows — with the model answer absent because it was never stored (see
     * `PaperAttempt::buildSnapshot()`). Images come back as fresh short-lived signed links,
     * which is why the snapshot holds ids and not URLs.
     *
     * @return list<array<string,mixed>>
     */
    protected function casesPayload(PaperAttempt $attempt, string $locale): array
    {
        $snapshot = collect((array) ($attempt->case_snapshot ?? []))->keyBy('id');
        $imageIds = $snapshot->flatMap(fn ($c) => (array) ($c['image_ids'] ?? []))->map('intval')->unique()->values();

        $images = $imageIds->isEmpty()
            ? collect()
            : Asset::query()->whereIn('id', $imageIds)->get()->keyBy('id');

        $number = 0;

        return collect((array) ($attempt->case_ids ?? []))->map(function ($id) use ($snapshot, $images, $locale, &$number) {
            $case = $snapshot->get((int) $id);

            if ($case === null) {
                return null;
            }

            $number++;

            return [
                'id'          => (int) $case['id'],
                'number'      => $number,
                'title'       => $this->translated($case['title'] ?? null, $locale),
                'brief'       => $this->translated($case['brief'] ?? null, $locale),
                'instruction' => $this->translated($case['instruction'] ?? null, $locale),
                'score_max'   => (float) ($case['score_max'] ?? 0),
                'images'      => collect((array) ($case['image_ids'] ?? []))
                    ->map(fn ($imageId) => $images->get((int) $imageId))
                    ->filter()
                    ->map(fn (Asset $asset) => [
                        'id'  => (int) $asset->id,
                        'url' => $asset->path,
                        'alt' => (string) ($asset->alt ?? ''),
                    ])
                    ->values()
                    ->all(),
            ];
        })->filter()->values()->all();
    }

    /**
     * What the candidate has written and given themselves so far, keyed by case id.
     *
     * @return array<int,array{id:int,report:string,score:float|null}>
     */
    protected function answersPayload(PaperAttempt $attempt): array
    {
        return CaseAnswer::query()
            ->where('enrolment_id', $attempt->enrolment_id)
            ->whereIn('case_id', (array) ($attempt->case_ids ?? []))
            ->get()
            ->mapWithKeys(fn (CaseAnswer $a) => [(int) $a->case_id => [
                'id'     => (int) $a->id,
                'report' => (string) $a->report,
                'score'  => $a->score === null ? null : (float) $a->score,
            ]])
            ->all();
    }

    /**
     * The model answers for a sitting's cases, read **live** from the case rows — the only
     * moment a candidate is entitled to them is after the paper has ended, and the only copy
     * is the case's own. Deleted cases are included: a sitting outlives the case bank.
     *
     * @return array<int,string> case id => html
     */
    protected function modelAnswers(PaperAttempt $attempt, string $locale): array
    {
        return ExamCase::withTrashed()
            ->whereIn('id', (array) ($attempt->case_ids ?? []))
            ->get()
            ->mapWithKeys(fn (ExamCase $c) => [(int) $c->id => (string) ($c->getTranslation('model_answer', $locale, true) ?? '')])
            ->all();
    }

    /**
     * The sitting's running total: what has been marked so far against what the cases as
     * served could yield.
     *
     * @return array{scored:float,max:float}
     */
    protected function totals(PaperAttempt $attempt): array
    {
        $scored = (float) CaseAnswer::query()
            ->where('enrolment_id', $attempt->enrolment_id)
            ->whereIn('case_id', (array) ($attempt->case_ids ?? []))
            ->sum('score');

        $max = (float) collect((array) ($attempt->case_snapshot ?? []))->sum(fn ($c) => (float) ($c['score_max'] ?? 0));

        return ['scored' => round($scored, 2), 'max' => round($max, 2)];
    }

    /**
     * Mark an enrolment complete once every active paper has an ended sitting, and active
     * again if one has not. The date column stays the authority on expiry — this touches only
     * the complete/active pair.
     */
    protected function refreshStatus(Enrolment $enrolment): void
    {
        if ($enrolment->hasExpired()) {
            return;
        }

        $paperIds = ExamPaper::query()->active()->where('product_id', $enrolment->product_id)->pluck('id');

        $ended = PaperAttempt::query()
            ->where('enrolment_id', $enrolment->id)
            ->whereIn('paper_id', $paperIds)
            ->whereNotNull('ended_at')
            ->distinct()
            ->count('paper_id');

        $status = $paperIds->isNotEmpty() && $ended >= $paperIds->count()
            ? Enrolment::STATUS_COMPLETE
            : Enrolment::STATUS_ACTIVE;

        if ($enrolment->status !== $status) {
            $enrolment->status = $status;
            $enrolment->save();
        }
    }

    /** A translations array from a snapshot, in the locale asked for, then English, then anything. */
    protected function translated(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        return (string) ($value[$locale] ?? $value['en'] ?? (reset($value) ?: ''));
    }
}
