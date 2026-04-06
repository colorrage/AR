<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'step_logs', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedBigInteger('step_id');
            $table->unsignedBigInteger('send_log_id')->nullable();
            $table->enum('status', ['scheduled', 'pending', 'sent', 'failed', 'skipped'])->default('scheduled');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('skip_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ab_variant_used', 1)->nullable();
            $table->string('send_key', 64)->unique()->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'step_id']);

            $table->foreign('enrollment_id')
                ->references('id')
                ->on($prefix . 'enrollments')
                ->cascadeOnDelete();

            $table->foreign('sequence_id')
                ->references('id')
                ->on($prefix . 'sequences')
                ->cascadeOnDelete();

            $table->foreign('step_id')
                ->references('id')
                ->on($prefix . 'steps')
                ->cascadeOnDelete();

            $table->foreign('send_log_id')
                ->references('id')
                ->on($prefix . 'send_logs')
                ->nullOnDelete();
        });

        // Add deferred FKs from send_logs to sequences and steps
        Schema::table($prefix . 'send_logs', function (Blueprint $table) use ($prefix) {
            $table->foreign('autoresponder_id')
                ->references('id')
                ->on($prefix . 'sequences')
                ->nullOnDelete();

            $table->foreign('autoresponder_step_id')
                ->references('id')
                ->on($prefix . 'steps')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        if (Schema::hasTable($prefix . 'send_logs')) {
            Schema::table($prefix . 'send_logs', function (Blueprint $table) {
                $table->dropForeign(['autoresponder_id']);
                $table->dropForeign(['autoresponder_step_id']);
            });
        }

        Schema::dropIfExists($prefix . 'step_logs');
    }
};
