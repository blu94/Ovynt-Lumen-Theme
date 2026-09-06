<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The candidate's side: what they were granted, what they were served, and what they wrote.
 *
 * Three tables, and the middle one is the reason the other two can be trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * A grant of access to one exam, for one candidate, for a window.
         *
         * Rows are **never reused**. A candidate whose window lapsed and who buys again gets a
         * new row; the old one keeps its dates and its answers, which is what makes "what did
         * they score last time" answerable at all. The current enrolment is the newest row.
         */
        Schema::create('lumen_enrolments', function (Blueprint $table) {
            $table->id();

            // No foreign key into `users` or `orders` — both are core's, and a theme is
            // uninstalled by dropping its own tables. Indexed instead, and existence is checked
            // where it is written.
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('exam_id')->constrained('lumen_exams')->cascadeOnDelete();

            /** Which order granted this, when one did. Null for a free exam or a staff grant. */
            $table->unsignedBigInteger('order_id')->nullable()->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            /** active · complete · expired. Derived by the repository, stored so it can be listed. */
            $table->string('status')->default('active');

            /**
             * `practice` or `timed`. Every enrolment begins in practice; moving to timed clears
             * the candidate's answers and restarts every clock, which is why it is recorded per
             * enrolment rather than chosen per sitting.
             */
            $table->string('mode')->default('practice');

            $table->timestamps();

            // "The newest enrolment for this candidate on this exam" is the single hottest
            // question in the product — every dashboard card asks it.
            $table->index(['user_id', 'exam_id', 'id']);
            $table->index(['status', 'expires_at']);
        });

        /**
         * One sitting of one paper — and the record of exactly what was served.
         *
         * **This table is why editing a case cannot rewrite somebody's finished exam.** When a
         * paper is opened, the ordered case ids and a snapshot of each case are frozen here. An
         * ended paper is replayed from those ids with trashed rows included, never from the live
         * case list, so deleting or rewording a case afterwards changes nothing that already
         * happened.
         */
        Schema::create('lumen_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrolment_id')->constrained('lumen_enrolments')->cascadeOnDelete();
            $table->foreignId('paper_id')->constrained('lumen_papers')->cascadeOnDelete();

            /** The ordered case ids served, as `[12, 15, 9]`. The replay list. */
            $table->json('case_ids')->nullable();

            /**
             * A frozen copy of each case as it was served — title, brief, instruction, score
             * ceiling, image ids.
             *
             * **The model answer is deliberately absent, and this is the one thing not carried
             * across from the source.** There, the snapshot contains each case's model answer
             * and the attempt is returned in the same response that serves the case list, so a
             * candidate reading the network tab can see the answers while still writing their
             * report. The source records this against itself as a known gap.
             *
             * Omitting it at the point the snapshot is BUILT is the strong fix. Hiding the field
             * on a model protects one serialisation path and not the next one somebody writes;
             * a value that was never stored cannot leak through any of them.
             * See `PaperAttempt::buildSnapshot()`.
             */
            $table->json('case_snapshot')->nullable();

            /** The paper's `case_set_version` at the moment this attempt opened. */
            $table->unsignedInteger('case_set_version')->default(1);

            /**
             * The clock, owned by the server. `seconds_remaining` may only ever decrease, and
             * `seconds_spent` may only ever increase — a client that reports otherwise is
             * ignored rather than trusted, which is the whole reason these live here and not in
             * the browser.
             */
            $table->unsignedInteger('seconds_remaining')->default(0);
            $table->unsignedInteger('seconds_spent')->default(0);

            /** Which case ids the candidate has actually opened, for the progress ticks. */
            $table->json('visited_case_ids')->nullable();

            $table->timestamp('started_at')->nullable();

            /**
             * Set once, and it is the point of no return: after this the paper is read-only,
             * the model answers become readable, and self-marking opens.
             */
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // "Is there an open attempt for this enrolment on this paper?" — asked on every
            // page open.
            $table->index(['enrolment_id', 'paper_id', 'ended_at']);
        });

        /**
         * What the candidate wrote for one case, and what they later gave themselves for it.
         *
         * The report and the score share a row because they are the same submission at two
         * moments: written during the sitting, marked after it ends. Splitting them would make
         * "did they answer this case" a join.
         */
        Schema::create('lumen_answers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrolment_id')->constrained('lumen_enrolments')->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained('lumen_attempts')->cascadeOnDelete();

            // Not a foreign key, and not for the usual reason: cases soft-delete, so the row
            // would survive — but a case deleted and its paper then force-deleted would take
            // the answer with it under a cascade, and an answer is evidence of what somebody
            // did. Indexed and resolved with `withTrashed()`.
            $table->unsignedBigInteger('case_id')->index();

            /** The candidate's free-text report. Plain text; nothing renders it as HTML. */
            $table->longText('report')->nullable();

            /**
             * The self-mark, validated between 0 and the case's `score_max` at the time it is
             * given. Null until the candidate marks it — which is different from zero, and the
             * scoring roll-up counts only marked cases.
             */
            $table->decimal('score', 5, 2)->nullable();

            $table->timestamp('scored_at')->nullable();

            $table->timestamps();

            /**
             * **One answer per case per enrolment, enforced by the database.**
             *
             * The source enforces this in application code ("re-saving updates in place rather
             * than accumulating duplicates") and then has to de-duplicate scores to the highest
             * per question when rolling up — which is what a missing constraint looks like from
             * downstream. A unique index means the roll-up can trust its input.
             */
            $table->unique(['enrolment_id', 'case_id']);
            $table->index(['attempt_id', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumen_answers');
        Schema::dropIfExists('lumen_attempts');
        Schema::dropIfExists('lumen_enrolments');
    }
};
