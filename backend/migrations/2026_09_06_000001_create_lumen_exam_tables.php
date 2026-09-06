<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authored side of an exam.
 *
 * **An exam is a product.** Not a record that names one — the product row *is* the exam, and
 * everything here hangs off `product_id`. Core's Products module owns the title, slug,
 * description, price, tax, status and catalogue presence; this theme appends an *Exam* tab to
 * that same form through `admin/extends/products.json` and stores what it owns below.
 *
 * The alternative — a separate `exams` table with its own identity, listed in its own sidebar
 * module — is what this theme shipped first. It worked, and it meant two records and two screens
 * for one thing an operator thinks of as one thing. `ModuleExtensionRegistry`'s own docblock
 * describes that shape as "a second top-level module describing a product from the outside" and
 * declines to endorse it: *"Neither is a design anyone chose; both are what the resolution order
 * left."*
 *
 * **No foreign key points into `products`.** A theme is uninstalled by dropping its own tables,
 * and a constraint reaching into a core table would make that fail on a shop that still has
 * products. Indexed instead, and existence is checked where it is written — the same rule
 * Saffron's `outlet_product` follows.
 *
 * These migrations are rewritten rather than superseded: the theme is 0.1.0, unreleased, and no
 * install carries data. A shipped version would get an additive migration instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * What a product needs in order to *be* an exam.
         *
         * One row per product, created the first time the Exam tab is saved. Presence alone does
         * not make a product an exam — `is_exam` does — so an operator can switch it off without
         * losing the access window they configured.
         */
        Schema::create('lumen_exams', function (Blueprint $table) {
            $table->id();

            // Unique: a product is one exam or none. The uniqueness is the whole reason this is
            // a settings row rather than an entity — there is nothing here to have two of.
            $table->unsignedBigInteger('product_id')->unique();

            $table->boolean('is_exam')->default(false);

            /**
             * How long access lasts once a candidate starts, in days. Copied onto each new
             * enrolment as `expires_at`, so changing it later affects future sittings only and
             * never shortens a window somebody already holds.
             */
            $table->unsignedSmallInteger('access_days')->default(30);

            /**
             * The percentage of the maximum a candidate is aiming at, shown before they have a
             * score of their own. 60 is the RCR's working figure; a column rather than a
             * constant because another board uses a different one.
             */
            $table->unsignedTinyInteger('ideal_percent')->default(60);

            /** Display only. The clock that runs belongs to the paper. */
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->timestamps();

            $table->index(['is_exam']);
        });

        Schema::create('lumen_papers', function (Blueprint $table) {
            $table->id();

            // The exam this belongs to, which is a product. No foreign key, per the note above.
            $table->unsignedBigInteger('product_id')->index();

            $table->json('title');
            $table->string('slug')->index();
            $table->json('description')->nullable();

            /** **This is the clock.** Seeded onto the attempt when a paper is opened. */
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            /** Bumped by hand when the case list changes materially; recorded on each attempt. */
            $table->unsignedInteger('case_set_version')->default(1);

            $table->string('status')->default('active');
            $table->integer('orders')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Every exam is allowed a "Paper 1", so the pair is what has to be unique.
            $table->unique(['product_id', 'slug']);
            $table->index(['product_id', 'status', 'orders']);
        });

        Schema::create('lumen_cases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('paper_id')->constrained('lumen_papers')->cascadeOnDelete();

            $table->json('title');
            $table->json('brief')->nullable();
            $table->json('instruction')->nullable();

            /**
             * **The model answer is a column here, not a row in the answers table.** A model
             * answer is authored content belonging to the case; a candidate's report is a
             * submission belonging to a sitting. Keeping them apart is what lets the answers
             * table carry a genuine uniqueness constraint.
             *
             * It is never included in an attempt snapshot — see the sitting migration.
             */
            $table->json('model_answer')->nullable();

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
