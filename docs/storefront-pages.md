# Storefront pages

The theme ships three sections. **Two of them need a Page with an exact slug**, and nothing
validates that at install — get the slug wrong and every link in the theme leads to a 404 with
nothing to explain why. Create these once, under **Pages**, and drop the section on each.

| Page slug | Section to add | What it does |
|---|---|---|
| `exams` | Exam Catalogue | Lists exams on sale, with the price from each exam's product |
| `exam` | Exam Papers | One exam's papers for the candidate who holds access |
| `dashboard` | Candidate Dashboard | The candidate's own exams, progress and score |

The slugs are not cosmetic: the theme's own links point at `/exams`, `/exam?e={slug}` and
`/dashboard`, so they have to match.

## Why the exam page uses `?e=` instead of `/exam/{slug}`

A prettier address is not available to any Ovynt package, and it is worth knowing why rather
than assuming it was laziness.

Core resolves a storefront path through a fixed sequence — the site root, a Page by slug, then
three hard-coded prefixes (`blog/`, `collections/`, `products/`). There is no registry a theme
can add to. The one event in that area, `PathNotResolved`, is a **redirect** seam and says so
outright: a listener "cannot write the response". And a theme cannot subscribe to events at all.

So the only address a theme can serve is one core already resolves: an ordinary Page. The exam is
named in the query string instead of the path. It works, it is bookmarkable, and it is honest
about the constraint. The fix is a core change, recorded as `ISSUES-CORE.md` C8.

## What the Exam Papers page does with no `?e=`

It does not error.

- **One enrolment** — goes straight to it, so a candidate with a single exam never sees a chooser.
- **Several** — renders a chooser of their own exams.
- **None** — points them at the catalogue.
- **An exam they do not hold** — "You do not have access to this exam." An exam slug that does
  not exist gets **exactly the same answer**, deliberately: a different message would let a
  stranger discover which exams exist by trying slugs.
- **An expired window** — the papers are withheld and access renewal is offered. Their answers
  are kept; buying again starts a fresh window.

## The Start button is disabled, on purpose

Opening a paper is a *write* — it creates the attempt that freezes the case list — and a theme
cannot register a route to accept one. The button is therefore rendered disabled with an
explanation rather than as a link that 404s.

It becomes live when core gains the storefront-write seam (`ISSUES-CORE.md` C1). Nothing else on
these pages is waiting on it.
