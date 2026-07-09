<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Tests\TestCase;

class SendLogTest extends TestCase
{
    private Sequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sequence = Sequence::create([
            'name' => 'Seq', 'slug' => 'sendlog-' . uniqid(),
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
    }

    public function test_send_log_is_created(): void
    {
        $log = SendLog::create([
            'autoresponder_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'pending',
        ]);

        $this->assertNotNull($log->id);
        $this->assertEquals('pending', $log->status);
    }

    public function test_tracks_opens(): void
    {
        $log = SendLog::create([
            'autoresponder_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
        ]);

        $log->increment('opens_count');
        $log->update(['opened_at' => now()]);

        $fresh = $log->fresh();
        $this->assertEquals(1, $fresh->opens_count);
        $this->assertNotNull($fresh->opened_at);
    }

    public function test_tracks_clicks(): void
    {
        $log = SendLog::create([
            'autoresponder_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
        ]);

        $log->increment('clicks_count');
        $log->update(['clicked_at' => now()]);

        $fresh = $log->fresh();
        $this->assertEquals(1, $fresh->clicks_count);
        $this->assertNotNull($fresh->clicked_at);
    }
}
