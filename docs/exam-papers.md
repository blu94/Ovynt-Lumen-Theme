# Papers

**Where:** Sidebar → **Lumen** → **Papers** · `/module/exam-papers`

A paper is a timed set of cases — the thing a candidate actually opens, times and finishes. An
exam's papers each end independently, and the exam counts as complete once every one of them
has been ended.

## The clock lives here

The exam's *Stated Duration* is display only. **This** duration is the timer that runs.

It is copied onto a sitting when the candidate opens the paper, so changing it later applies to
new sittings only — anyone already part-way through keeps the time they were given. That is
deliberate: an operator saving an unrelated field must not take time away from someone
mid-paper. Because the effect is invisible from the form, every duration change is written to
the activity log with a note saying so.

In **Timed** mode the server ends the paper when the clock reaches zero, and nothing in it can
change afterwards. In **Practice** mode the countdown simply runs on and nothing is closed.

## Slugs are unique within the exam

Every exam is allowed a "Paper 1". The slug only has to be unique among its own exam's papers,
because that is how a paper is addressed — by exam, then by paper.

## Case Set Version

Bump this by hand after materially changing the case list. It records which revision a sitting
was served, which is useful when comparing cohorts.

It does **not** change what anybody is served. A sitting always replays from its own frozen copy
of the cases, so editing the list never rewrites a finished exam either way.

## Deleting

Refused once the paper has been sat, because a sitting's record hangs off this row. Set it to
**Inactive** instead: it leaves new sittings, and every finished one still replays.
