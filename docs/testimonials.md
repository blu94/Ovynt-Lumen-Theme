# Testimonials

**Where:** Sidebar → **Lumen** → **Testimonials** · `/module/testimonials`

A testimonial is a quote from a candidate that you have reviewed and chosen to publish. The
**Testimonials** block on any page shows the published ones.

## Why this is not a form

Candidates do not submit testimonials directly. What they submit is **feedback** — through the
site-feedback form — and that lands under **Forms → Leads** as correspondence, with everything
they wrote, their email and the time it arrived. It stays there.

A testimonial is different in kind: it is content. It has been trimmed to the sentence that says
something, it carries a name the candidate is happy to have shown, it has a rating and an order,
and it is either published or it is not. Filing it as a lead would give it none of those, and
filing every lead as a testimonial would publish correspondence. So the two are kept apart, and
turning one into the other is a decision somebody makes on this screen.

## Adding one

1. Read the feedback under **Forms → Site feedback → View Leads**. The form asks candidates to
   tick *You may quote me on the site* — publish only those, and ask before using a full name.
2. **Add Testimonial.** Paste the part worth quoting into **Quote**, set the **Name** and a line
   about them under **Role or Place**, and give it a **Rating** if they gave one.
3. Put the lead's ID in **Curated From Lead**, so the original message can be found in six months.
4. Leave it as **Draft** until it has been checked, then set it to **Published**.

| Field | What it does |
|---|---|
| **Quote** | Plain text, per locale. The full message stays on the lead. |
| **Rating** | 0–5, shown as stars. No rating hides them. |
| **Name / Role or Place** | What the storefront shows under the quote. |
| **Curated From Lead** | Optional. The lead this came from, for the audit trail. |
| **Status** | Only **Published** quotes reach the storefront. |
| **Order** | Lower first; ties show the newest first. |

## Showing them

Add the **Testimonials** block to a page — the home page and the exam catalogue are the usual
places. It has a heading, an intro, a maximum count and a column count, and it draws only
published quotes in the order set here. A page with the block and no published quotes renders
nothing to visitors (and a note to you while debug mode is on).

## Deleting

Allowed, and soft: a quote removed by mistake can be restored from the database. Unlike an
enrolment, a testimonial is nobody's history — the correspondence it came from is still under
Leads.
