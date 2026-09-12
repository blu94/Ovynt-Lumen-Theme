# Ovynt Lumen Theme

An exam-preparation theme for [Ovynt](https://github.com/blu94/Ovynt): timed, case-based papers
with image-led reporting and self-marking against a model answer. Structurally derived from the
Ella and Saffron themes — same layout shape, settings-schema system, section-driver pattern and
Vue-CDN conventions.

## Status

**Early. The authoring and commerce halves work; the exam player does not exist yet.**

| Area | State |
|---|---|
| Exams, papers and cases on one screen — the product's Exam tab, cases nested inside each paper | Built, verified in the browser end to end |
| Enrolments — staff grant, per-paper progress, guarded edits | Built |
| Selling access — core product, checkout, enrolment written in the order's transaction | Built |
| Notifications — access granted, new enrolment | Built |
| Storefront — exam catalogue and candidate dashboard, server-rendered | Built |
| Contact, feedback and case-report forms — core's Forms module, placed by an Inquiry Form block | Built |
| Testimonials — a module of curated quotes, and a block that renders the published ones | Built |
| The exam's own address — `/exam/{slug}`, served through core's `storefront.paths` seam | Built |
| **Sitting a paper — the server side**: open, progress, end, report, self-mark, switch mode, as six `storefront.actions` | Built, exercised end to end against the running install |
| **Sitting a paper — the player**: `/exam/{slug}/{paper}`, timer, image viewer, report box, self-marking, mode switch | Built, driven in the browser |
| Expiry reminders | Declared, not sent — needs a scheduled job |

A candidate can buy an exam, be granted access, open it at its own address, sit each paper
against the clock, and mark themselves against the model answers.

## The player

`/exam/{slug}/{paper}` resolves the paper (the same `ExamPath` resolver, template
`pages/exam-paper.blade.php`) and mounts one Vue app from `frontend/assets/js/player.js`. It
opens the sitting with `attempts.open`, pings `attempts.progress` on the interval the theme
setting names, autosaves each report with `answers.save`, ends with `attempts.end`, and marks with
`answers.mark`. The image viewer is dependency-free: drag to pan, wheel or `+`/`-` to zoom, `R`
to rotate, `W` then drag for window/level, `F` to fit, `←`/`→` between cases. Signed image links
live ten minutes, so the page re-opens the sitting every eight to refresh them, and once more on
any image that fails to load.

The page never decides anything the server decides: a timed paper that reaches zero locally
calls `attempts.end` and shows whatever came back, and a ping that answers `ended` moves the page
to review. The mode switch on the papers page is one confirm and one call to `enrolments.mode`.

## How a sitting reaches the server

Sitting a paper is a sequence of writes from a signed-in candidate — open a paper, save progress,
end it, save a report, submit a self-mark, switch mode — and **a theme cannot register a single
HTTP route**. Ovynt states this in five separate files; a theme is weaker than a plugin, which
cannot either.

Core therefore owns one route, `POST /api/storefront/actions/{name}`, and this theme declares
what lives behind it in `manifest.json` under `storefront.actions` — six classes in
`backend/Storefront/Actions/`, one per write, each resolving the caller's enrolment and refusing
with a 422 the page can show:

| Action | Class | What it does |
|---|---|---|
| `attempts.open` | `OpenAttempt` | Start or resume a paper: freezes the case list into an attempt, returns the cases without their model answers |
| `attempts.progress` | `SaveProgress` | The periodic ping. Time spent only grows, time left only shrinks; a timed paper ends itself at zero |
| `attempts.end` | `EndAttempt` | End the paper and, only now, return the model answers |
| `answers.save` | `SaveAnswer` | The report for one case, one row per case, refused once the paper has ended |
| `answers.mark` | `MarkAnswer` | The self-mark, in half steps, against the ceiling the case carried when served |
| `enrolments.mode` | `SwitchMode` | Practice to timed, clearing every answer and sitting; back again only if the operator allows it |

Core keeps everything a package must not re-implement: who is asking (the storefront token —
an admin's identifies nobody), whether they may ask, validation against each handler's own rules,
a transaction that rolls back on any throw, the throttle and the JSON envelope. The design is the
repository root's `STOREFRONT-PACKAGE-SEAMS-SPEC.md`; the register entries are `ISSUES-CORE.md`
C8 and C14.

Reads never needed any of this, which is why the catalogue and dashboard came first: the
storefront renders theme Blade holding this theme's own repositories, and the signed-in
candidate is resolved from the HttpOnly `customer_access_token` cookie (see
`backend/Support/CurrentCandidate.php`).

## How it is put together

```
Product     IS the exam — title, price, tax, catalogue listing (core's own record)
└── Exam tab   access window, target score, and the papers repeater
    └── Paper    a timed set of cases; THIS carries the clock that runs
        └── Case   images, clinical brief, instruction, model answer (its own dialog, inside the paper's)
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
validates that at install. See [`docs/storefront-pages.md`](docs/storefront-pages.md).

The exam itself lives at `/exam/{slug}`, resolved by `backend/Storefront/ExamPath.php` through
core's `storefront.paths` seam and rendered by `frontend/blade/pages/exam.blade.php`. That
template still draws the operator's `exam` Page — the Exam Papers block sits on it, and it is
also what the bare `/exam` chooser shows — so the address is the package's and the layout stays
the operator's.

## Working on it

```powershell
# From the repository root, not this directory
.\scripts\import-theme.ps1 -Theme lumen
```

**Nothing is live until it is imported.** Core reads a theme's PHP from the repository's `theme/`
directory only when that directory is mounted into the container, and `docker-compose.dev.yml`
does not mount it — so section drivers, Blade, assets *and* `backend/` all come from the deployed
tree. An edit that "does nothing" is almost always a missing import. A change to any JSON schema
or migration triggers a full re-install; anything else syncs just the changed files.

### Demo content

`backend/Seeders/DemoExamSeeder.php` writes one exam — two papers, seven cases with generated
placeholder images on the protected disk — plus the three storefront pages, an enrolment for
`user@ovynt.com` and a finished, self-marked sitting of Paper 1, so every screen has something to
show. It goes through the theme's real handlers, is safe to re-run, and is invoked by hand:

```powershell
docker exec -u www-data ovynt_app php -r "require '/var/www/vendor/autoload.php'; `$app = require '/var/www/bootstrap/app.php'; `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); print_r((new Theme\Backend\Seeders\DemoExamSeeder)->run());"
```

`backend/Seeders/StarterFormsSeeder.php` is the same idea for content a real site needs rather
than demo data: the contact, site-feedback and case-report forms under Forms, and a page for
each carrying an Inquiry Form block. Same invocation with `StarterFormsSeeder`; it never
overwrites a form or page the operator already has.

### Messages and testimonials

Contact, feedback and case reports are core Forms placed by the theme's **Inquiry Form** block,
so their fields and notifications are the operator's to change. A testimonial is *not* a form:
candidates send feedback, which lands in Leads as correspondence, and what gets published is a
row in the theme's **Testimonials** module — content somebody reviewed, with a status, a rating
and an order — rendered by the **Testimonials** block. Modelling a thing by where it arrived
rather than by what it is was the one habit of the source platform's inquiry inbox deliberately
not carried across.

The import script compiles `frontend/assets/css/scss/main.scss` to `theme.css` and refuses the
import on three lints: a dead `@include`/`@extends` target, a section schema with no renderer,
and drift between `admin/sections/*.json` and the manifest. `scss/` is excluded from the package,
so **the compiled `theme.css` is what ships and must be committed**.

## Core defects this theme surfaced

All are recorded in the repository root's `ISSUES-CORE.md`:

- **C6** — `AssetRepository` hard-codes `'App\Models\' . class_basename()` for the morph type, so
  a theme-owned model can never use core's media helpers. `ExamCases::attachImages()` writes the
  polymorphic columns itself as a workaround.
- **C7** — `CustomerToken` answers *whether* a customer is signed in but never *who*, so
  `CurrentCandidate` re-implements the cookie read to get the user.
- **C8** — a package cannot serve a storefront path for its own entities, hence `/exam?e={slug}`.
- **C9** — a package can only use the icons core's build already scanned; six of ours rendered blank.
- **C10** — the admin dropzone previews a protected upload from `/api/assets/{id}/view`, a route
  that does not exist, so every case image shows a broken thumbnail after upload. The upload and
  the attach are fine; only the preview is dead.
- **C11** — the active theme is cached forever under a Redis key nothing namespaces or heals; a
  stale id silently hides the Exam tab and every other theme contribution while the storefront
  keeps working.
- **C12** — a contribution whose handler lacks `ModuleFieldHandler` renders its tab and drops every
  value at save with only a log warning. This theme shipped that way until the save was run for real.
- **C13** — the form's default seeding descends into repeaters, so a nested row's defaults land on
  the parent payload.

## Documentation

Operator guides ship in `docs/` and are served by Ovynt's own Documentation screen:
`exams.md`, `enrolments.md`, `testimonials.md`, `forms.md`, `storefront-pages.md`.

## Licence

Proprietary. Copyright (c) 2026 Ovynt Labs — see [LICENSE](LICENSE). One production installation
per licence; no redistribution, resale or derivative works. Third-party components under
`vendor/` and `node_modules/` keep their own licences. The full licensing model — core, themes,
free plugins and paid plugins — is in [LICENSING.md](https://github.com/blu94/Ovynt/blob/main/LICENSING.md).
