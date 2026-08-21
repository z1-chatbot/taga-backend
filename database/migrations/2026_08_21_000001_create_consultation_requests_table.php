<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation requests raised from the storefront widget, and the thread of
 * replies that services them.
 *
 * A request is a support ticket: a shopper asks to speak to a practitioner of a
 * given kind (doctor, dentist, pharmacist...), an admin picks it up, replies,
 * and works it through to a resolution. Guests can raise one — the widget is on
 * every page, including pages nobody signs in to read — so `user_id` is
 * nullable and `session_id` carries the guest the same way prescriptions do.
 *
 * `reference` is what the requester is told to quote. It is generated, not the
 * primary key, so a request can be looked up (and read back by its guest owner)
 * without exposing how many exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            // Signed-in shopper, or a guest identified by the storefront's
            // X-Guest-ID. Exactly one of these is set.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_id')->nullable();

            // Who they want to see, and how to reach them.
            $table->string('practitioner_type', 50);
            $table->string('name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->enum('preferred_contact', ['email', 'phone', 'whatsapp'])->default('email');
            $table->string('preferred_time')->nullable();

            $table->string('subject')->nullable();
            $table->text('message');

            $table->enum('status', ['open', 'in_progress', 'scheduled', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');

            // Set once a slot is agreed; shown to the requester in the widget.
            $table->timestamp('scheduled_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // Denormalised so the admin queue can sort on "waiting longest for a
            // human" without joining the thread on every row.
            $table->timestamp('last_reply_at')->nullable();
            $table->enum('last_reply_by', ['customer', 'admin'])->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['practitioner_type', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('session_id');
        });

        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_request_id')->constrained()->cascadeOnDelete();

            $table->enum('author_type', ['customer', 'admin']);
            // Null for a guest's own messages — there is no account behind them.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');

            $table->text('body');

            // Staff-only note. Never returned by any customer-facing endpoint,
            // so notes live in the same thread as replies without leaking.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['consultation_request_id', 'is_internal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_messages');
        Schema::dropIfExists('consultation_requests');
    }
};
