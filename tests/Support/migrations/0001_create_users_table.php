<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal host `users` table for tests.
 *
 * Exists so `tests/Support/App/Models/User.php` — which implements `Subscribable`
 * and is the configured `subscriber_model` — can actually be created, making the
 * host-model recipient branch testable.
 *
 * Testbench's `loadLaravelMigrations()` was the intended mechanism and does not
 * work here: it runs migrations through `MigrateProcessor`, which shells out to
 * artisan and therefore opens a second connection. The suite uses SQLite
 * `:memory:`, which is per-connection, so those tables are invisible to the test.
 * Loading an in-process migration is the only approach compatible with `:memory:`.
 *
 * Deliberately minimal: `locale` is included because the package's
 * `subscriber_columns.locale` mapping needs a column to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
