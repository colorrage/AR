<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'send_logs', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('autoresponder_id')->nullable();
            $table->unsignedBigInteger('autoresponder_step_id')->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->string('email')->index();
            $table->string('language', 10)->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('unsubscribe_token', 64)->unique()->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('opens_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('autoresponder_id');
            $table->index('subscriber_id');

            $table->foreign('campaign_id')
                ->references('id')
                ->on($prefix . 'campaigns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'send_logs');
    }
};
