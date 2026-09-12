# Storefront pages

The theme ships three sections. **Two of them need a Page with an exact slug**, and nothing
validates that at install — get the slug wrong and every link in the theme leads to a 404 with
nothing to explain why. Create these once, under **Pages**, and drop the section on each.

| Page slug | Section to add | What it does |
|---|---|---|
| `exams` | Exam Catalogue | Lists exams on sale, with the price from each exam's product |
| `exam` | Exam Papers | One exam's papers for the candidate who holds access |
| `dashboard` | Candidate Dashboard | The candidate's own exams, progress and score |

The slugs are not cosmetic: the theme's own links point at `/exams` and `/dashboard`, so those
two have to match. The exam itself is at `/exam/{slug}` — an address the theme serves through
core's `storefront.paths` seam, not a Page — but the `exam` Page is the **layout** every one of
those addresses renders with, and it is what the bare `/exam` shows. Leave it out and every exam
page is blank.

Two more blocks go wherever you like, with no slug to get right:

| Block | What it does |
|---|---|
| Inquiry Form | A form from the Forms module — contact, feedback, a case report — with the signed-in candidate's details filled in. See [forms.md](forms.md) |
| Testimonials | Published quotes from the Testimonials module. See [testimonials.md](testimonials.md) |

`DemoExamSeeder` creates the three pages above with their blocks, and `StarterFormsSeeder`
creates `/contact`, `/feedback` and `/report-a-case` with an Inquiry Form each; both are run by
hand and described in the theme README.

## How `/exam/{slug}` is served, and why it once was `?e=`

Core resolves a storefront path through a fixed sequence — the site root, a Page by slug, a
package's own prefixes, then three of core's own (`blog/`, `collections/`, `products/`). The
package step is the `storefront.paths` seam: this theme's `manifest.json` declares the prefix
`exam` and the class `backend/Storefront/ExamPath.php`, which answers with the product that
carries an exam switched on. Core renders the answer with `pages/exam.blade.php`, guards it as
customer-only — a stranger is sent to `/login?redirect=…` — and never caches it publicly.

That step did not exist when the theme was first written; core's list could not be added to,
and `PathNotResolved` is a redirect seam that "cannot write the response". So the exam was named
in a query string on a Page — `/exam?e={slug}` — recorded as `ISSUES-CORE.md` C8 and since
built. The Exam Papers block still honours `?e=` as a fallback, for links written before the
seam and for a core too old to have it, and the `exam` Page is still needed: it is the layout.

## What the Exam Papers page does with no exam named

It does not error.

- **One enrolment** — goes straight to it, so a candidate with a single exam never sees a chooser.
- **Several** — renders a chooser of their own exams.
- **None** — points them at the catalogue.
- **An exam they do not hold** — "You do not have access to this exam." An exam slug that does
  not exist gets **exactly the same answer**, deliberately: a different message would let a
  stranger discover which exams exist by trying slugs.
- **An expired window** — the papers are withheld and access renewal is offered. Their answers
  are kept; buying again starts a fresh window.

## Opening a paper

**Start**, **Resume** and **Review** link to `/exam/{slug}/{paper}`, the player. It needs no
Page: the address is resolved by the theme and rendered by its own template. Opening it is a
write — it creates the sitting that freezes the case list — and the page makes that call itself,
so a candidate who opens the same paper in two tabs gets one sitting, not two. What the player
does, and which keys it answers to, is in the README.

The **Switch to timed mode** control on the exam page clears every report and sitting under that
exam after a confirm; the wording says so before it happens. **Return to practice mode** appears
only when the theme setting *Allow Returning to Practice* is on, and clears just the same.
