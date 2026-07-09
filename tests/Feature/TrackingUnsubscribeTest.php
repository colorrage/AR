<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Tests\TestCase;

class TrackingUnsubscribeTest extends TestCase
{
    public function test_track_open_returns_gif(): void
    {
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 'track-seq-' . uniqid(),
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $log = SendLog::create([
            'autoresponder_id' => $sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
            'unsubscribe_token' => bin2hex(random_bytes(32)),
        ]);

        $response = $this->get(route('autoresponder.track.open', ['id' => $log->id]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/gif');
    }

    public function test_track_open_increments_count(): void
    {
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 'track-open-' . uniqid(),
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $log = SendLog::create([
            'autoresponder_id' => $sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
            'unsubscribe_token' => bin2hex(random_bytes(32)),
        ]);

        $this->get(route('autoresponder.track.open', ['id' => $log->id]));

        $fresh = $log->fresh();
        $this->assertEquals(1, $fresh->opens_count);
        $this->assertNotNull($fresh->opened_at);
    }

    public function test_track_click_redirects(): void
    {
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 'track-click-' . uniqid(),
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $log = SendLog::create([
            'autoresponder_id' => $sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
            'unsubscribe_token' => bin2hex(random_bytes(32)),
        ]);

        $encodedUrl = base64_encode('http://localhost');
        $response = $this->get(route('autoresponder.track.click', [
            'id' => $log->id,
            'url' => $encodedUrl,
        ]));

        $response->assertRedirect();
        $this->assertEquals(1, $log->fresh()->clicks_count);
    }

    public function test_unsubscribe_show_page(): void
    {
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 'unsub-show-' . uniqid(),
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $token = bin2hex(random_bytes(32));
        $log = SendLog::create([
            'autoresponder_id' => $sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
            'unsubscribe_token' => $token,
        ]);

        $response = $this->get(route('autoresponder.unsubscribe', ['token' => $token]));

        $response->assertStatus(200);
    }

    public function test_unsubscribe_process(): void
    {
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 'unsub-proc-' . uniqid(),
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $token = bin2hex(random_bytes(32));
        SendLog::create([
            'autoresponder_id' => $sequence->id,
            'email' => 'test@example.com',
            'language' => 'en',
            'subject' => 'Test',
            'body_html' => '<p>Test</p>',
            'status' => 'sent',
            'unsubscribe_token' => $token,
        ]);

        Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'test@example.com',
            'state' => 'active', 'enrolled_at' => now(), 'dedupe_key' => 'dk-unsub',
        ]);

        $response = $this->post(route('autoresponder.unsubscribe.process', ['token' => $token]));

        $response->assertStatus(200);
        $this->assertDatabaseHas('ar_unsubscribes', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('ar_enrollments', ['email' => 'test@example.com', 'state' => 'unsubscribed']);
    }

    public function test_unsubscribe_invalid_token(): void
    {
        $response = $this->get(route('autoresponder.unsubscribe', ['token' => 'invalid-token']));

        $response->assertStatus(200);
    }
}
