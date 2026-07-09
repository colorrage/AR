<?php

namespace ColorrageAR\Autoresponder\Tests\Unit;

use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class InfrastructureTest extends TestCase
{
    public function test_autoresponder_tables_are_migrated(): void
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ar_%' ORDER BY name");
        $tableNames = array_column($tables, 'name');

        $this->assertContains('ar_sequences', $tableNames);
        $this->assertContains('ar_steps', $tableNames);
        $this->assertContains('ar_enrollments', $tableNames);
        $this->assertContains('ar_templates', $tableNames);
        $this->assertContains('ar_campaigns', $tableNames);
        $this->assertContains('ar_send_logs', $tableNames);
        $this->assertContains('ar_unsubscribes', $tableNames);
    }

    public function test_config_loaded_correctly(): void
    {
        $this->assertEquals('ar_', config('autoresponder.table_prefix'));
        $this->assertEquals('autoresponder', config('autoresponder.route_prefix'));
        $this->assertEquals('emails', config('autoresponder.queue'));
        $this->assertEquals('array', config('mail.default'));
        $this->assertEquals('sync', config('queue.default'));
        $this->assertEquals('sqlite', config('database.default'));
    }

    public function test_package_routes_registered(): void
    {
        $this->assertNotNull(
            route('autoresponder.track.open', ['id' => 1], false)
        );
        $this->assertNotNull(
            route('autoresponder.track.click', ['id' => 1, 'url' => base64_encode('https://example.com')], false)
        );
        $this->assertNotNull(
            route('autoresponder.unsubscribe', ['token' => 'test-token'], false)
        );
    }
}
