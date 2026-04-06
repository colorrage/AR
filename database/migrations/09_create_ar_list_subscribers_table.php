<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'list_subscribers', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('list_id');
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('locale', 10)->nullable();
            $table->enum('status', ['active', 'unsubscribed'])->default('active');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'email']);
            $table->index('subscriber_id');

            $table->foreign('list_id')
                ->references('id')
                ->on($prefix . 'mailer_lists')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'list_subscribers');
    }
};
