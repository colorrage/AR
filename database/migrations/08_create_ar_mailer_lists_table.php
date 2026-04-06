<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::create($prefix . 'mailer_lists', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['manual', 'dynamic'])->default('manual');
            $table->json('filter_config')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedInteger('subscriber_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::dropIfExists($prefix . 'mailer_lists');
    }
};
