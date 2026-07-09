<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'sequences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('trigger_type');
            $table->json('trigger_config')->nullable();
            $table->string('entry_filter_type')->default('all');
            $table->json('entry_filter_config')->nullable();
            $table->enum('status', ['draft', 'active', 'paused'])->default('draft');
            $table->boolean('is_active')->default(true);
            $table->string('stop_sequence_on_event')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->string('default_locale', 10)->default('en');
            $table->boolean('enable_utm_tracking')->default(false);
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->unsignedInteger('throttle_per_minute')->default(0);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'sequences');
    }
};
