# Cases

**Where:** Sidebar → **Lumen** → **Cases** · `/module/exam-cases`

One case is one study to report: a set of images, the clinical brief, and the instruction.

## Images are stored privately

Case images go to the **protected** store, not the public one. They are never served from a
public folder, and every view is a short-lived signed link rather than a permanent address.

Upload them in the order they should be read — a plain film and its lateral are a sequence, not
a set. Removing an image here detaches it from the case; the file itself stays in the media
library and can be reused.

> **What this does and does not protect.** The images are not reachable by guessing a URL and
> are not left in a public directory. A link is time-limited, but within its window it works
> more than once and works for anyone holding it, so it protects against casual copying rather
> than against a determined candidate sharing a link quickly. Making a link single-use and
> checking who is asking are both core changes and are on the register.

## The model answer

Written here, revealed to the candidate **only once they end the paper**.

This is not merely hidden by the page. The candidate's sitting is served a frozen copy of each
case that genuinely does not contain the model answer, so there is nothing to find in the
network response either. It is read live from this record at the moment the paper ends. (The
system this theme is modelled on froze the answer into that copy and returned it on every
resume, which is exactly the leak this avoids.)

## Maximum Score

The ceiling a candidate's self-mark is validated against, and what this case contributes to the
paper total. Half marks are allowed.

## Deleting

Always allowed, and always safe. A sitting that already served the case replays it from its own
frozen copy, and answers written against it keep resolving. Deleting a case removes it from
**new** sittings only.
