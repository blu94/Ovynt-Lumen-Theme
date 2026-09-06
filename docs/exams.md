# Exams

**Where:** Sidebar → **Lumen** → **Exams** · `/module/exams`

An exam is the thing a candidate buys. It holds **papers**; each paper holds **cases**. Nothing
is served to a candidate until the exam is Active and has at least one active paper with at
least one active case.

## Creating one

| Field | What it does |
|---|---|
| **Exam Title** | What candidates see on the catalogue. Translatable. |
| **Slug** | Used in the exam's address. Leave it empty and it is generated from the title, then made unique automatically. |
| **Subtitle / Description** | Catalogue copy. Both translatable. |
| **Sold As** | The product that sells this exam — see below. Leave it empty to make the exam free. |
| **Access Window** | How many days access lasts from the moment a candidate starts. |
| **Stated Duration** | Display only, shown on the exam card. |
| **Target Score** | The percentage of the maximum a candidate aims at before they have a score of their own. 60 is the RCR's working figure. |
| **Status** | Only **Active** exams reach candidates. |

## An exam has no price, and that is deliberate

The price, tax, currency and any discount live on the **product** named in *Sold As*. Create the
product first under **Products**, price it however you like, then name it here.

This means there is only ever one figure: what the customer is charged at checkout is what the
exam costs, because the exam does not carry a second opinion about it. It also means everything
core already does for a product works here for free — discount codes, tax rules, invoices, and
every payment gateway the shop has configured.

> **Set the product to not require shipping.** An exam has nothing to post. A product with
> *Requires shipping* off means checkout never asks the candidate for an address and charges no
> delivery fee.

**Buying it grants access automatically.** Nothing else has to be wired up: when the order is
created, the theme writes the candidate's enrolment inside the same database transaction. If the
enrolment cannot be written the whole order is rolled back and nothing is charged — an order that
takes money for access nobody holds is the one outcome worth refusing a sale to prevent.

**Leave *Sold As* empty for a free exam.** Nothing sells it, so nothing has to be bought.

## Access windows

The window is copied onto each enrolment when it is created, as *today + N days*. Changing
**Access Window** later therefore affects **future sittings only** — it can never shorten a
window somebody has already been given, which is the behaviour you want when a candidate is
part-way through.

A candidate whose window has closed does not lose anything: their answers and scores stay. They
buy the exam again and get a fresh enrolment, and the old one remains readable.

## Deleting

Deletion is **refused once anybody has enrolled**, and the screen says so. A candidate's papers,
attempts and answers all hang off the exam row, so deleting it would destroy the record of every
sitting.

Set the status to **Inactive** instead. That removes it from the catalogue and from new sittings,
and leaves everything already sat exactly as it was.
