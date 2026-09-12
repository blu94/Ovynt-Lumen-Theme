<?php

namespace Theme\Backend\Schedule;

use App\Contracts\Notification\Notifier;
use App\Contracts\Package\ScheduledTask;
use App\Services\Notification\NotificationTypeRegistry;
use Theme\Backend\Models\Enrolment;

/**
 * Once a day: close the windows that have passed, and warn the candidates whose are about to.
 *
 * Declared in `manifest.json` under `schedule`, run by core's `alvyth:package-tasks`. Two jobs,
 * one clock, because both are about the same date:
 *
 * - **The sweep.** `Enrolment::hasExpired()` treats the date as the authority and the status
 *   column as a cache, so nothing *breaks* while the column lags — but the Enrolments list and
 *   the dashboard read the column, and "Active" beside a date last month is a lie the operator
 *   has to notice. Rows whose date has passed are set Expired here.
 * - **The reminder.** *Access ending soon* (`enrolment_expiring`) was declared as a
 *   notification type and shown on the preferences screen long before anything sent it. Sent
 *   once per enrolment, seven days out, to a candidate whose access is still usable — and
 *   `reminded_at` is the record that it went, so a day the scheduler ran twice, or a cache
 *   that forgot the last run, cannot send it twice.
 *
 * Nothing here throws on a notification: `Notifier` never does, by contract. A database that
 * cannot answer throws, and core logs it and moves on to the next task.
 */
class EnrolmentExpiry implements ScheduledTask
{
    /** How far out the reminder goes. Seven days is the source platform's figure. */
    public const REMIND_DAYS_BEFORE = 7;

    public function frequency(): string
    {
        return self::DAILY;
    }

    public function run(): void
    {
        $this->sweep();
        $this->remind();
    }

    /** Set Expired on every row whose window has closed. A bulk update: no row is "changed" by a person. */
    protected function sweep(): void
    {
        Enrolment::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('status', '!=', Enrolment::STATUS_EXPIRED)
            ->update(['status' => Enrolment::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    /** One reminder per enrolment, seven days before its window closes. */
    protected function remind(): void
    {
        $registry = app(NotificationTypeRegistry::class);
        $notifier = app(Notifier::class);
        $type     = $registry->themeKey('enrolment_expiring');

        $due = Enrolment::query()
            ->with('product:id,title')
            ->whereNull('reminded_at')
            ->where('status', '!=', Enrolment::STATUS_EXPIRED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(self::REMIND_DAYS_BEFORE))
            ->orderBy('id')
            ->get();

        foreach ($due as $enrolment) {
            // Recorded before the send, not after: a send that hangs and a task that is then
            // killed must not leave a row that will be reminded again tomorrow.
            $enrolment->forceFill(['reminded_at' => now()])->saveQuietly();

            $title = $enrolment->product?->getTranslation('title', app()->getLocale(), false)
                ?: ('#' . $enrolment->product_id);

            $notifier->toUser(
                (int) $enrolment->user_id,
                $type,
                [
                    'exam_title' => $title,
                    'days_left'  => max(1, (int) ceil(now()->diffInHours($enrolment->expires_at, false) / 24)),
                ],
                $enrolment,
            );
        }
    }
}
