# Ovynt Lumen Theme

An exam-preparation theme for [Ovynt](https://github.com/blu94/Ovynt): timed, case-based papers
with image-led reporting and self-marking against a model answer. Structurally derived from the
Ella and Saffron themes — same layout shape, settings-schema system, section-driver pattern and
Vue-CDN conventions.

## Status

**Early. The authoring and commerce halves work; the exam player does not exist yet.**

| Area | State |
|---|---|
| Exams as an Exam tab on Products; papers and cases as their own screens | Built, verified against a running install |
| Enrolments — staff grant, per-paper progress, guarded edits | Built |
| Selling access — core product, checkout, enrolment written in the order's transaction | Built |
| Notifications — access granted, new enrolment | Built |
| Storefront — exam catalogue and candidate dashboard, server-rendered | Built |
| **Sitting a paper — timer, report box, image viewer, self-marking** | **Not built. Blocked on core** |
| Expiry reminders | Declared, not sent — needs a scheduled job |

A candidate can buy an exam and be granted access today. They cannot yet sit it.

## Why the player is blocked

Sitting a paper is a sequence of writes from a signed-in candidate — enrol, save progress, end a
paper, save a report, submit a self-mark — and **a theme cannot register a single HTTP route**.
Ovynt states this in five separate files; a theme is weaker than a plugin, which cannot either.

Reads are fine, which is why the catalogue and dashboard exist: the storefront renders theme
Blade holding this theme's own repositories, and the signed-in candidate is resolved from the
HttpOnly `customer_access_token` cookie (see `backend/Support/CurrentCandidate.php`).

Unblocking it needs one core change: a declarative storefront-write seam, shaped like the
`checkout.guards` / `cart.line_pricer` / `checkout.writers` seams a theme already has. Tracked as
item C1 in the build plan.

## How it is put together

```
Exam        the thing that is bought — access window, target score
└── Paper   a timed set of cases; THIS carries the clock that runs
    └── Case  images, clinical brief, reporting instruction, model answer
```

Three tables, all prefixed `lumen_`, plus three for the candidate's side —
`lumen_enrolments`, `lumen_attempts`, `lumen_answers`.

### An exam *is* a product

Not a record that names one. The product row is the exam: it carries the title, description,
price, tax, discounts, catalogue listing and status, and this theme appends an **Exam** tab to
core's own Products form through `admin/extends/products.json`. Papers, cases and enrolments all
key on `product_id`; `lumen_exams` holds only the four fields a product has no concept of.

One record, one screen. An earlier version shipped a separate Exams module beside Products, and
`ModuleExtensionRegistry`'s own docblock describes that shape as "a second top-level module
describing a product from the outside" and declines to endorse it: *"Neither is a design anyone
chose; both are what the resolution order left."*

A product priced at zero is a free exam — nothing else is configured.

Buying grants access automatically: `backend/Writers/ExamEnrolmentWriter.php` is declared in
`manifest.json` as a `checkout.writers` entry and runs **inside the order's transaction**. It
fails closed — if the enrolment cannot be written the order rolls back and nothing is charged,
because an order that takes money for access nobody holds is the defect worth refusing a sale to
prevent.

Switching the Exam tab off removes it from the catalogue and stops new enrolments without
deleting anything: papers, cases and every sitting are kept, and switching it back on restores
the exam. That is why it is a flag rather than the presence of a row.

### An attempt is frozen when it opens

Opening a paper records the ordered case ids and a snapshot of each case. A finished paper
replays from those ids `withTrashed()`, so editing or deleting a case afterwards never rewrites
somebody's sitting.

**The model answer is deliberately absent from that snapshot.** Not hidden — never stored. It is
read live from the case only once the paper has ended. (The system this theme is modelled on
froze it into the snapshot and returned it on every resume, so a candidate reading the network
response could see the answers while still writing.)

### Case images are private

They go to Ovynt's `protected` disk, which nginx never serves; every view is a short-lived signed
link. Two known limits, both core's: a link works more than once inside its window, and it does
not check who is asking. Tracked as `ISSUES-CORE.md` C3.

## Pages the operator must create

Three sections need a Page with an exact slug — `exams`, `exam` and `dashboard` — and nothing
validates that at install. See [`docs/storefront-pages.md`](docs/storefront-pages.md), which also
explains why the exam page is `/exam?e={slug}` rather than `/exam/{slug}`: core resolves
storefront paths through a fixed list no package can extend, and `PathNotResolved` redirects
rather than renders. Recorded as `ISSUES-CORE.md` C8.

## Working on it

```powershell
# From the repository root, not this directory
.\scripts\import-theme.ps1 -Theme lumen
```

**Only `backend/` is live in local dev.** Section drivers, Blade templates and assets are read
from the deployed tree, so those need an import before an edit does anything. An edit that
"does nothing" is almost always this.

The import script compiles `frontend/assets/css/scss/main.scss` to `theme.css` and refuses the
import on three lints: a dead `@include`/`@extends` target, a section schema with no renderer,
and drift between `admin/sections/*.json` and the manifest. `scss/` is excluded from the package,
so **the compiled `theme.css` is what ships and must be committed**.

## Two core defects this theme surfaced

Both are recorded in the repository root's `ISSUES-CORE.md`:

- **C6** — `AssetRepository` hard-codes `'App\Models\' . class_basename()` for the morph type, so
  a theme-owned model can never use core's media helpers. `ExamCaseRepository::attachImages()`
  writes the polymorphic columns itself as a workaround.
- **C7** — `CustomerToken` answers *whether* a customer is signed in but never *who*, so
  `CurrentCandidate` re-implements the cookie read to get the user.

## Documentation

Operator guides ship in `docs/` and are served by Ovynt's own Documentation screen:
`exams.md`, `exam-papers.md`, `exam-cases.md`, `enrolments.md`, `storefront-pages.md`.
