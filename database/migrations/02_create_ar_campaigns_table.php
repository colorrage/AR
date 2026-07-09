<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'campaigns', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('subject')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('filter_type')->nullable();
            $table->json('filter_params')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'queued', 'sending', 'sent', 'failed', 'cancelled'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->boolean('enable_utm_tracking')->default(false);
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on($prefix . 'templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'campaigns');
    }
};
