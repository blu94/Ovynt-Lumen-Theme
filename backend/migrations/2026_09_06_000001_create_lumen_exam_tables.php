<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authored side of an exam: what staff write, and what a candidate sits.
 *
 * Three nested levels, named for what a radiologist calls them rather than for what the
 * source system called them internally:
 *
 *   - **Exam** — the thing that is bought. "Rapid Reporting".
 *   - **Paper** — a timed set of cases. The source calls this a "package"; `packages` is far
 *     too generic a table name to put in a shared schema, and "paper" is the word the exam
 *     itself uses.
 *   - **Case** — one set of images with a reporting instruction. The source calls this a
 *     "question" and then labels it "Case 01" everywhere in its own UI, which is the tell.
 *
 * **Every table is prefixed `lumen_`.** A theme's migrations run against the same schema as
 * core's and every other installed package's. `exams`, `papers` and `cases` are words another
 * package will want; `lumen_papers` is not.
 *
 * **No foreign key points into a core table.** A theme is uninstalled by dropping its own
 * tables, and a constraint reaching into `products` or `users` would make that fail on a shop
 * that still has either. The repositories check existence instead — the same rule Saffron's
 * `outlet_product` migration follows and for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumen_exams', function (Blueprint $table) {
            $table->id();

            $table->json('title');
            $table->string('slug')->unique();
            $table->json('subtitle')->nullable();
            $table->json('description')->nullable();

            /**
             * **The exam does not carry a price, and that is the whole integration.**
             *
             * The source puts `price` on the exam and grows its own commercial vocabulary from
             * it: free when zero, locked when above zero and unpurchased, renewable, discountable.
             * Ovynt already owns every one of those concepts on `products` — price, tax class,
             * discounts, currency, the order that proves a purchase — so an exam that carried its
             * own price would be a second answer to "what does this cost", free to disagree with
             * the one the customer is actually charged at checkout.
             *
             * So an exam names a product and stops there. Selling, pricing, discounting and
             * refunding stay core's; what an exam is and how long access lasts stay the theme's.
             *
             * A null product is a **free exam**: nothing sells it, so nothing has to be bought,
             * and `ExamEnrolmentWriter` never sees it because it never reaches a checkout.
             */
            $table->unsignedBigInteger('product_id')->nullable()->index();

            /**
             * Display only, and deliberately so. The clock that actually runs belongs to the
             * paper (see below) — an exam-level duration that governed nothing would be the
             * kind of setting that looks authoritative and is ignored.
             */
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            /**
             * How long access lasts once a candidate starts, in days. Copied onto each new
             * enrolment as `expires_at`, so changing it later affects future sittings only and
             * never shortens a window somebody has already been granted.
             */
            $table->unsignedSmallInteger('access_days')->default(30);

            /**
             * The percentage of the maximum the candidate is aiming at, shown before they have
             * a score of their own. 60 is the RCR's own working figure; it is a column rather
             * than a constant because a different exam board uses a different one.
             */
            $table->unsignedTinyInteger('ideal_percent')->default(60);

            $table->string('status')->default('draft');
            $table->integer('orders')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'orders']);
        });

        Schema::create('lumen_papers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_id')->constrained('lumen_exams')->cascadeOnDelete();

            $table->json('title');
            $table->string('slug')->index();
            $table->json('description')->nullable();

            /**
             * **This is the clock.** Seeded onto the attempt when a paper is opened and counted
             * down by the server, never by the browser.
             */
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            /**
             * Bumped by hand when the case list changes materially. An attempt records the
             * version it was served, so a finished sitting can say which revision it sat even
             * though it replays from its own snapshot either way.
             */
            $table->unsignedInteger('case_set_version')->default(1);

            $table->string('status')->default('active');
            $table->integer('orders')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The storefront asks for one paper of one exam by slug; the admin lists a paper's
            // siblings in order. Both are covered here.
            $table->unique(['exam_id', 'slug']);
            $table->index(['exam_id', 'status', 'orders']);
        });

        Schema::create('lumen_cases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('paper_id')->constrained('lumen_papers')->cascadeOnDelete();

            $table->json('title');

            /** The clinical context the candidate reads before looking at the images. */
            $table->json('brief')->nullable();

            /** What they are being asked to produce — "Report the study." */
            $table->json('instruction')->nullable();

            /**
             * **The model answer is a column here, not a row in the answers table.**
             *
             * The source stores it as an `Answer` of type `STANDARD`, in the same table
             * candidates' own reports live in. That conflates two different things: a model
             * answer is authored content belonging to the case, written once by staff and
             * versioned with it; a candidate's report is a submission belonging to a sitting.
             * They have different owners, different lifetimes and different access rules, and
             * the only thing they share is being prose.
             *
             * Keeping them apart is what lets the answers table carry a genuine uniqueness
             * constraint — one report per candidate per case — which it could not if staff
             * content lived in it too.
             *
             * It is never included in an attempt snapshot. See the sitting migration.
             */
            $table->json('model_answer')->nullable();

            /**
             * The ceiling a self-mark is validated against, and this case's contribution to the
             * paper total. Decimal because the RCR's descriptors are whole numbers but half
             * marks are given in practice.
             */
            $table->decimal('score_max', 5, 2)->default(5);

            $table->string('status')->default('active');
            $table->integer('orders')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['paper_id', 'status', 'orders']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumen_cases');
        Schema::dropIfExists('lumen_papers');
        Schema::dropIfExists('lumen_exams');
    }
};
