<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Testimonials — what candidates said, curated and published.
 *
 * **Not a lead, on purpose.** A testimonial arrives as correspondence (the site-feedback form,
 * an email, a message) and that copy stays in Leads, where correspondence lives. What is
 * published on the storefront is a piece of content somebody reviewed, trimmed and approved —
 * with a status, an order and a rating — and content of that kind is a module. The source
 * platform filed both in one moderated inbox; modelling a thing by where it arrived rather than
 * by what it is was the mistake this table declines to inherit.
 *
 * `lead_id` remembers which lead a quote was curated from, when it was. Indexed, no foreign
 * key: `leads` is core's table and this theme is uninstalled by dropping its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumen_testimonials', function (Blueprint $table) {
            $table->id();

            /** The name the candidate wants shown, and where they are or what they do. */
            $table->string('author', 120);
            $table->string('role', 160)->nullable();

            /** The quote itself, per locale like every other piece of storefront copy. */
            $table->json('quote');

            /** 0–5; null when the candidate gave none. */
            $table->unsignedTinyInteger('rating')->nullable();

            /** draft · published. Only published rows reach the storefront. */
            $table->string('status')->default('draft');
            $table->integer('orders')->default(0);

            $table->unsignedBigInteger('lead_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'orders']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumen_testimonials');
    }
};
