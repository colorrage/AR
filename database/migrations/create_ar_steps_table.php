<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'steps', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sequence_id');
            $table->unsignedInteger('step_number');
            $table->string('name');
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('subject_override')->nullable();
            $table->enum('delay_type', ['from_trigger', 'from_prev_step', 'fixed_time'])->default('from_prev_step');
            $table->unsignedInteger('delay_value')->default(0);
            $table->enum('delay_unit', ['minutes', 'hours', 'days', 'weeks'])->default('hours');
            $table->time('window_start')->nullable();
            $table->time('window_end')->nullable();
            $table->string('condition_type')->default('none');
            $table->json('condition_config')->nullable();
            $table->boolean('ab_test_enabled')->default(false);
            $table->unsignedBigInteger('ab_template_id')->nullable();
            $table->unsignedTinyInteger('ab_weight_a')->default(50);
            $table->unsignedTinyInteger('ab_weight_b')->default(50);
            $table->string('stop_sequence_on_event')->default('none');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['sequence_id', 'step_number']);

            $table->foreign('sequence_id')
                ->references('id')
                ->on($prefix . 'sequences')
                ->cascadeOnDelete();

            $table->foreign('template_id')
                ->references('id')
                ->on($prefix . 'templates')
                ->nullOnDelete();

            $table->foreign('ab_template_id')
                ->references('id')
                ->on($prefix . 'templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'steps');
    }
};
