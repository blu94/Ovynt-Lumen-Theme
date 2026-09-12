# Contact, feedback and case reports

**Where:** the forms live under Sidebar → **Forms**; each sits on a page through the theme's
**Inquiry Form** block.

An exam site takes three kinds of message, and this theme handles all three with Ovynt's own
Forms module rather than an inbox of its own: the fields, the validation, the lead storage and
the *notify on submit* email are core's, so you can change any of them without touching the
theme.

| Form (slug) | Page | What it is for |
|---|---|---|
| `contact` | `/contact` | A question about an exam, access, or an order |
| `site-feedback` | `/feedback` | How the site and the exams are working — and the source of testimonials |
| `case-report` | `/report-a-case` | A problem with one specific case: an image, a brief, a model answer |

Every submission is a **Lead** under the form it was sent to. Set **Notify Users** on each form
so the right people hear about a new one.

## Getting them on a fresh install

The theme ships the three forms and their pages as starter content, created by hand once:

```powershell
docker exec -u www-data ovynt_app php -r "require '/var/www/vendor/autoload.php'; `$app = require '/var/www/bootstrap/app.php'; `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); print_r((new Theme\Backend\Seeders\StarterFormsSeeder)->run());"
```

It never overwrites: a form that already exists by slug is left as you have it, and a page that
already has content is kept. Run it again after deleting one to get it back.

## The Inquiry Form block

Add it to any page and set **Form** to a form's slug. It draws the form, posts submissions, and
adds two things the form cannot know on its own:

- **Who is asking.** A signed-in candidate's name and email are filled in for them. They can
  change them — somebody may want a reply elsewhere — but a report is never a stranger's message.
- **What it is about.** A link into `/report-a-case` may carry `?exam=…&paper=…&case=…`. Those
  are filled into the **Exam**, **Paper** and **Case** fields and locked, so a report arrives
  attached to the study it was raised against rather than described from memory. Other forms
  have no such fields and ignore the parameters.

The block's heading and intro are yours; the form's own title shows only if you switch it on.

## Testimonials are not a fourth form

The feedback form asks candidates to tick *You may quote me on the site*. A message with that
tick is still a lead — correspondence — and publishing it is a separate decision made under
**Testimonials**, where the quote becomes content with a status, a rating and an order. See
[testimonials.md](testimonials.md).
