# Exams

**Where:** Sidebar → **Products** → open a product → the **Exam** tab

There is no Exams screen, and that is the design. **An exam is a product.** The product carries
the title, description, price, tax, discounts, catalogue listing and status; the Exam tab adds
the handful of things a product has no concept of.

One record, one screen. Earlier versions of this theme shipped a separate Exams module beside
Products, which meant creating two records and keeping them in step for one thing an operator
thinks of as one thing.

## Turning a product into an exam

1. Create the product under **Products** as you would any other, and price it.
2. Turn **Requires Shipping** off. An exam has nothing to post, and with it off checkout never
   asks the candidate for an address or charges a delivery fee.
3. Set the product **Active**, or it will not appear in the catalogue however the Exam tab is
   configured.
4. Open the **Exam** tab and switch on **This product is an exam**.

| Field | What it does |
|---|---|
| **This product is an exam** | Off, the product sells as an ordinary product and grants nothing. |
| **Access Window (days)** | How long access lasts once a candidate starts. |
| **Target Score (%)** | The percentage of the maximum shown before they have a score of their own. |
| **Stated Duration** | Display only. Each paper carries the timer that actually runs. |

5. Add its **Papers** in the repeater on the same tab.

Then build each paper's **Cases** from the Cases screen.

## Papers live on this tab

A paper is a timed set of cases — the thing a candidate opens, times and finishes. Add, edit and
reorder them in the **Papers** table on the Exam tab; the order you drag them into is the order
candidates meet them in.

| Field | What it does |
|---|---|
| **Paper Title** | What the candidate sees. |
| **Duration (minutes)** | **The timer that actually runs.** Copied onto a sitting when the paper is opened, so changing it later applies to new sittings only — anyone part-way through keeps the time they were given. |
| **Case Set Version** | Bump by hand after materially changing the case list. It records which revision a sitting was served; it never changes what anybody is served. |
| **Status** | Inactive removes it from new sittings. Anyone mid-paper is unaffected and every finished one still replays. |

**Removing a row is not a hard delete.** The paper is soft-deleted and its cases are kept, so
putting it back restores them.

**A paper that has been sat is never removed at all.** If you delete its row, it is kept and set
**Inactive** instead, and the activity log records why. An attempt is the record of what a
candidate was served, and it hangs off that row — losing it would destroy their sitting. The old
Papers screen refused the delete with a message; a repeater row has nowhere to show one, so it
reaches the same outcome quietly.

**Cases are deliberately not on this tab.** A repeater *can* nest, but a paper holds ten to forty
cases, each with images and a rich-text model answer. Putting those two dialogs deep would carry
an entire exam's content in every product save, so cases keep their own screen, filtered by
paper.

## The price is the product's, and there is only one

Nothing here stores a price. What the customer is charged at checkout is what the exam costs,
because there is no second figure to disagree with it — and every discount code, tax rule and
payment gateway the shop already has works on it untouched.

**A product priced at zero is a free exam.** Nothing special is configured; the catalogue reads
the price and shows Free.

**Buying it grants access automatically.** The enrolment is written inside the same transaction
that creates the order, so by the time the customer sees their receipt they have access. If the
enrolment cannot be written the whole order rolls back and nothing is charged — an order that
takes money for access nobody holds is the one outcome worth refusing a sale to prevent.

## Switching an exam off keeps everything

Turning **This product is an exam** off removes it from the catalogue and stops new enrolments.
Nothing is deleted: the papers, the cases and every sitting anybody has taken are kept, and
switching it back on restores the exam exactly as it was.

That is why it is a switch rather than the presence of a record — and why the access window you
configured survives being switched off and on.

## Access windows

The window is copied onto each enrolment as *today + N days*. Changing **Access Window** later
therefore affects **future sittings only** — it can never shorten a window somebody already
holds, which is the behaviour you want when a candidate is part-way through.

A candidate whose window closed does not lose anything: their answers and scores stay. They buy
the exam again and get a fresh enrolment; the old one remains readable under **Enrolments**.

## Deleting

Delete the product as you would any other. Ovynt's own rules apply — and note that the papers,
cases and sittings belong to the product, so removing it removes the exam. To take an exam out
of circulation while keeping its history, switch the Exam tab off or set the product Inactive.
