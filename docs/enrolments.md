# Enrolments

**Where:** Sidebar → **Lumen** → **Enrolments** · `/module/enrolments`

An enrolment is one candidate's access to one exam, for one window. This is the support desk's
screen: who has access to what, until when, and what they actually did.

## You do not need this for an ordinary sale

Buying an exam grants access on its own. The enrolment is written inside the same transaction
that creates the order, so by the time the customer sees their receipt they already have access.

This screen exists for the cases a purchase does not cover:

- **Comping a candidate** — a reviewer, a colleague, a prize.
- **Making good on a payment that failed to settle** — the money arrived but the order did not.
- **Extending somebody whose window lapsed mid-sitting** — change the expiry rather than making
  them buy again.

Granting here deliberately **bypasses the purchase gate**, and records no order — a hand grant
is not a sale, and recording one would put something nobody paid for into the revenue figures.
Put why you did it in **Reason**: it is not stored on the enrolment, it is written to the
activity log alongside the grant, which is where somebody will look in six months.

## What you can and cannot change

| Field | Editable | Why |
|---|---|---|
| Candidate | **No, after creation** | Moving an enrolment to a different person would silently reassign somebody else's answers. |
| Exam | **No, after creation** | Same reason — it would reattach a sitting to an exam it was never sat under. |
| Expires | Yes | The normal way to extend or shorten access. |
| Status | Yes | Active, Complete or Expired. |
| Mode | Yes | Practice or Timed — though this is normally the candidate's own choice. |

> **Changing Mode to Timed clears their answers and restarts every clock.** That is what the
> mode switch means, and it is why candidates are warned before they do it themselves. Do not
> change it on somebody's behalf mid-sitting.

## There is no delete, on purpose

An enrolment is the record of what a candidate sat. Deleting it would take their attempts and
their answers with it.

Set the status to **Expired** instead. That closes access immediately and keeps everything — and
unlike a delete, it can be undone.

## What They Actually Did

The bottom of the form is one row per paper, straight from the server's own record: time spent,
time left, how many cases were answered, how many were marked, and when the paper ended.

This is the evidence for a dispute about a timer or a lost answer — what the server recorded,
rather than what anybody remembers. The clock is the server's throughout: a sitting reports its
progress and the server only ever accepts a value that moves time forward, so a client cannot
give itself more.

## Notifications

Granting access — by purchase or by hand — sends the candidate **Access granted**, and tells
staff who can view enrolments that somebody enrolled. Both are ordinary Ovynt notifications:
candidates can turn theirs off under their own preferences, and the email template is editable
under **Settings → Mail → Templates** once it has fired once.

**Access ending soon** goes out once per enrolment, seven days before the window closes, from
the theme's daily task (`backend/Schedule/EnrolmentExpiry.php`, declared under `schedule` in the
manifest and run by core's `ovynt:package-tasks`). The same task sets **Expired** on every
enrolment whose date has passed, so the list stops showing *Active* beside a date last month.
Both depend on the scheduler: on a containerised install it runs itself; on shared hosting it runs
only if the operator has added the single cron line, which the Health screen reports on. A
reminder that has gone is recorded on the enrolment, so a day the scheduler ticks twice cannot
send it twice.
