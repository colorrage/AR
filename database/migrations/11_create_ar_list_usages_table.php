<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'list_usages', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('list_id');
            $table->unsignedBigInteger('campaign_id');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign('list_id')
                ->references('id')
                ->on($prefix . 'mailer_lists')
                ->cascadeOnDelete();

            $table->foreign('campaign_id')
                ->references('id')
                ->on($prefix . 'campaigns')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'list_usages');
    }
};
