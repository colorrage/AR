<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::table($prefix . 'list_subscribers', function (Blueprint $table) {
            // Import provenance only: which file or batch a row came from, and when.
            // Consent text, legal basis and retention deliberately live in the host —
            // the package records where an address came from, never why it was allowed.
            $table->string('import_source')->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    public function down(): void
    {
        $prefix = config('autoresponder.table_prefix', 'ar_');

        Schema::table($prefix . 'list_subscribers', function (Blueprint $table) {
            $table->dropColumn(['import_source', 'imported_at']);
        });
    }
};
