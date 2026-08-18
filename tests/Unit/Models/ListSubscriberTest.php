<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Tests\TestCase;
use DateTimeInterface;
use Illuminate\Support\Facades\Schema;

use function ColorrageAR\Autoresponder\ar_table;

class ListSubscriberTest extends TestCase
{
    private function makeList(): MailerList
    {
        return MailerList::create([
            'name' => 'Provenance List',
            'type' => 'manual',
            'status' => 'active',
        ]);
    }

    public function test_import_provenance_columns_round_trip(): void
    {
        $list = $this->makeList();

        ListSubscriber::create([
            'list_id' => $list->id,
            'email' => 'provenance@example.com',
            'status' => 'active',
            'subscribed_at' => now(),
            'import_source' => 'contacts-2026-08.csv',
            'imported_at' => now(),
        ]);

        $row = ListSubscriber::where('email', 'provenance@example.com')->firstOrFail();

        $this->assertSame('contacts-2026-08.csv', $row->import_source);
        $this->assertNotNull($row->imported_at);
    }

    public function test_imported_at_is_cast_to_a_date_instance(): void
    {
        $list = $this->makeList();

        ListSubscriber::create([
            'list_id' => $list->id,
            'email' => 'cast@example.com',
            'status' => 'active',
            'subscribed_at' => now(),
            'import_source' => 'cast.csv',
            'imported_at' => '2026-08-17 09:30:00',
        ]);

        $row = ListSubscriber::where('email', 'cast@example.com')->firstOrFail();

        // A string here would silently break any date formatting a host or the
        // Filament stub does on the import report.
        $this->assertInstanceOf(DateTimeInterface::class, $row->imported_at);
        $this->assertSame('2026-08-17 09:30:00', $row->imported_at->format('Y-m-d H:i:s'));
    }

    public function test_provenance_columns_are_nullable_for_non_imported_rows(): void
    {
        $list = $this->makeList();

        // Rows added by any other path — a host form, syncFromModel, addSubscriber —
        // carry no provenance, and must remain valid.
        ListSubscriber::create([
            'list_id' => $list->id,
            'email' => 'manual@example.com',
            'status' => 'active',
            'subscribed_at' => now(),
        ]);

        $row = ListSubscriber::where('email', 'manual@example.com')->firstOrFail();

        $this->assertNull($row->import_source);
        $this->assertNull($row->imported_at);
    }

    public function test_provenance_migration_is_reversible(): void
    {
        $table = ar_table('list_subscribers');

        $this->assertTrue(Schema::hasColumn($table, 'import_source'));
        $this->assertTrue(Schema::hasColumn($table, 'imported_at'));

        // RefreshDatabase only ever runs up(), so down() would otherwise ship unexercised —
        // and dropping columns is the half most likely to break on SQLite.
        $migration = require __DIR__
            . '/../../../database/migrations/13_add_import_provenance_to_ar_list_subscribers.php';

        $migration->down();

        $this->assertFalse(Schema::hasColumn($table, 'import_source'));
        $this->assertFalse(Schema::hasColumn($table, 'imported_at'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn($table, 'import_source'));
        $this->assertTrue(Schema::hasColumn($table, 'imported_at'));
    }

    public function test_import_config_defaults_are_published(): void
    {
        $this->assertSame(
            ['email' => 'email', 'name' => 'name', 'locale' => 'locale'],
            config('autoresponder.import.columns'),
        );
        $this->assertSame(',', config('autoresponder.import.delimiter'));
        $this->assertSame(500, config('autoresponder.import.chunk_size'));
        $this->assertSame(1000, config('autoresponder.import.max_rejected_rows'));
    }
}
