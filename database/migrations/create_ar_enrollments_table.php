<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'enrollments', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->string('email')->index();
            $table->unsignedInteger('current_step_number')->default(0);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->enum('state', ['active', 'paused', 'completed', 'exited', 'unsubscribed', 'failed'])->default('active');
            $table->string('exit_reason')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_step_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->json('trigger_data')->nullable();
            $table->string('ab_variant_assigned', 1)->nullable();
            $table->string('dedupe_key', 64)->unique()->nullable();
            $table->string('processing_token')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamps();

            $table->index(['sequence_id', 'email']);
            $table->index(['state', 'next_run_at']);

            $table->foreign('sequence_id')
                ->references('id')
                ->on($prefix . 'sequences')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'enrollments');
    }
};
