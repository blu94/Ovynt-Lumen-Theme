<?php

namespace Theme\Backend\Writers;

use App\Contracts\Notification\Notifier;
use App\Contracts\Storefront\OrderWriter;
use App\Models\Order;
use App\Services\Notification\NotificationTypeRegistry;
use Illuminate\Validation\ValidationException;
use Theme\Backend\Models\Enrolment;
use Theme\Backend\Models\Exam;

/**
 * Grant exam access at the moment the order is created, inside its transaction.
 *
 * Declared in `manifest.json` as `checkout.writers`, and called by core from within
 * `OrderRepository::create()` after the order and its items exist.
 *
 * **This seam exists because the other two cannot allocate.** A checkout guard may only refuse,
 * and it answers before the order row exists; a line pricer only prices. Granting access after
 * the order was created — from a listener, or from a return URL — is a check-then-write with
 * the order creation sitting in the gap, and the customer who closes the tab on the payment
 * page never gets what they paid for.
 *
 * **It fails closed, deliberately, and that is the opposite of the guard beside it.**
 * `OrderWriterRegistry::write()` does not catch: anything thrown here rolls the whole order
 * back — no order, no items, no stock taken, no gateway session. A guard failing open costs a
 * shop one commercial rule for one request; access failing open takes a candidate's money and
 * gives them nothing, which is the defect worth refusing a sale to avoid.
 */
class ExamEnrolmentWriter implements OrderWriter
{
    public function write(Order $order): void
    {
        $order->loadMissing('items');

        $productIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        // Only the products this theme actually sells access to. An order for a mug and an exam
        // grants access to the exam and says nothing about the mug — a shop is allowed to sell
        // both, and a writer that assumed every line was an exam would be wrong on its first
        // mixed basket.
        //
        // `is_exam` is checked, not merely the presence of a row: a product whose Exam tab was
        // configured and then switched off must not keep granting access to buyers.
        $exams = Exam::query()
            ->whereIn('product_id', $productIds)
            ->where('is_exam', true)
            ->get();

        if ($exams->isEmpty()) {
            return;
        }

        foreach ($exams as $exam) {
            $this->grant($order, $exam);
        }
    }

    /**
     * One enrolment per exam on the order.
     *
     * **Idempotent by construction.** A gateway that returns the customer to the site *and*
     * fires its webhook can reach order creation twice for one payment; core's own settle path
     * can also re-enter. Re-granting would hand the candidate a second, later-expiring window
     * for one purchase — so an enrolment already recorded against this order is left exactly as
     * it is rather than refreshed.
     */
    protected function grant(Order $order, Exam $exam): void
    {
        $userId = (int) ($order->user_id ?? 0);

        if ($userId <= 0) {
            /**
             * **A guest cannot be granted access, so the order must not stand.**
             *
             * Access is held against a user id — there is nowhere to put a grant for somebody
             * who has no account, and no way for them to sign in later and find it. Refusing at
             * this point costs the customer a clear message before any money moves; accepting
             * would take payment for something they could never open.
             *
             * A shop selling exams should require an account at checkout. This is the backstop
             * for when that setting is off, not a substitute for it.
             */
            throw ValidationException::withMessages([
                'checkout' => 'Exam access is held against an account, so this order needs you to be signed in. Please create an account or sign in, then order again — nothing has been charged.',
            ]);
        }

        $already = Enrolment::query()
            ->where('user_id', $userId)
            ->where('product_id', $exam->product_id)
            ->where('order_id', $order->id)
            ->exists();

        if ($already) {
            return;
        }

        // The window starts now and runs for the exam's own access period. Copied onto the row
        // rather than derived on read, so changing `access_days` later cannot shorten a window
        // somebody has already been given.
        $days = max(1, (int) ($exam->access_days ?: 30));

        $enrolment = Enrolment::create([
            'user_id'    => $userId,
            'product_id' => $exam->product_id,
            'order_id'   => $order->id,
            'started_at' => now(),
            'expires_at' => now()->addDays($days),
            'status'     => Enrolment::STATUS_ACTIVE,
            'mode'       => Enrolment::MODE_PRACTICE,
        ]);

        $this->announce($enrolment, $exam, $userId);
    }

    /**
     * Tell the candidate they have access, and tell staff somebody enrolled.
     *
     * **Safe to call from inside the order's transaction, and only because `Notifier` never
     * throws.** Core's contract says it answers `0` on anything it cannot do rather than
     * raising — so a mail server that is down, or a type that failed to register, cannot roll
     * back a paid order. Anything that could throw does not belong in a writer.
     *
     * **The key is asked for, never written out.** The registry prefixes a theme's types with a
     * slug derived from the manifest *title*, not its `slug` field — this theme declares
     * `"slug": "lumen"` and installs as `ovynt-lumen-theme`. A hardcoded `theme:lumen.` prefix
     * would be a key the registry never issued: `Notifier` would log "unknown notification
     * type", answer 0, and the type would sit visibly on the preferences screen doing nothing.
     */
    protected function announce(Enrolment $enrolment, Exam $exam, int $userId): void
    {
        $registry = app(NotificationTypeRegistry::class);
        $notifier = app(Notifier::class);

        // The exam's title is the PRODUCT's title — there is no second name to drift from it.
        $product = $exam->product;
        $title   = $product?->getTranslation('title', app()->getLocale(), false) ?: ('#' . $exam->product_id);

        $notifier->toUser(
            $userId,
            $registry->themeKey('enrolment_granted'),
            [
                'exam_title' => $title,
                'expires_on' => $enrolment->expires_at?->toFormattedDateString() ?? '—',
            ],
            $enrolment,
        );

        $notifier->toStaff(
            $registry->themeKey('new_enrolment'),
            [
                'exam_title' => $title,
                'candidate'  => optional(\App\Models\User::find($userId))->name ?? "Candidate #{$userId}",
            ],
            $enrolment,
        );
    }
}
