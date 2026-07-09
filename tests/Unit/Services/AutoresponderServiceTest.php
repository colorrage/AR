<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Services;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Services\AutoresponderService;
use ColorrageAR\Autoresponder\Tests\Support\SubscriberMocker;
use ColorrageAR\Autoresponder\Tests\TestCase;

class AutoresponderServiceTest extends TestCase
{
    private AutoresponderService $service;
    private Sequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);

        $this->service = app(AutoresponderService::class);

        $this->sequence = Sequence::create([
            'name' => 'Welcome Series', 'slug' => 'welcome-series',
            'status' => 'active', 'trigger_type' => 'user_registration', 'default_locale' => 'en',
        ]);

        Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);
    }

    public function test_enroll_creates_enrollment(): void
    {
        $subscriber = SubscriberMocker::default();
        $enrollments = $this->service->enroll('user_registration', $subscriber);
        $this->assertCount(1, $enrollments);
        $this->assertEquals('active', $enrollments->first()->state);
    }

    public function test_enroll_dedup(): void
    {
        $subscriber = SubscriberMocker::default();
        $first = $this->service->enroll('user_registration', $subscriber);
        $second = $this->service->enroll('user_registration', $subscriber);
        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function test_enroll_skips_unsubscribed(): void
    {
        $email = 'unsubscribed@example.com';
        Unsubscribe::create(['email' => $email]);
        $subscriber = (new SubscriberMocker)->withEmail($email)->make();
        $result = $this->service->enroll('user_registration', $subscriber);
        $this->assertCount(0, $result);
    }

    public function test_manual_enroll(): void
    {
        $result = $this->service->manualEnroll($this->sequence->id, 'manual@example.com');
        $this->assertNotNull($result);
        $this->assertEquals('manual@example.com', $result->email);
    }

    public function test_manual_enroll_nonexistent(): void
    {
        $result = $this->service->manualEnroll(99999, 'test@example.com');
        $this->assertNull($result);
    }

    public function test_has_active_enrollment(): void
    {
        Enrollment::create([
            'sequence_id' => $this->sequence->id, 'email' => 'existing@example.com',
            'state' => 'active', 'enrolled_at' => now(), 'dedupe_key' => 'dk-existing',
        ]);
        $this->assertTrue($this->service->hasActiveEnrollment($this->sequence, 'existing@example.com'));
    }

    public function test_pause_resume_complete_cancel(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id, 'email' => 'test@example.com',
            'state' => 'active', 'enrolled_at' => now(), 'dedupe_key' => 'dk-prc',
        ]);

        $this->service->pauseEnrollment($enrollment);
        $this->assertEquals('paused', $enrollment->fresh()->state);

        $this->service->resumeEnrollment($enrollment);
        $this->assertEquals('active', $enrollment->fresh()->state);

        $this->service->completeEnrollment($enrollment);
        $this->assertEquals('completed', $enrollment->fresh()->state);
    }

    public function test_get_enrollments_ready_to_process(): void
    {
        Carbon::setTestNow(now());
        Enrollment::create([
            'sequence_id' => $this->sequence->id, 'email' => 'due@example.com',
            'state' => 'active', 'next_run_at' => now()->subHour(), 'enrolled_at' => now()->subHour(),
            'dedupe_key' => 'dk-due',
        ]);
        $due = $this->service->getEnrollmentsReadyToProcess();
        $this->assertCount(1, $due);
        Carbon::setTestNow(null);
    }

    public function test_get_sequences_by_trigger(): void
    {
        Sequence::create([
            'name' => 'Another', 'slug' => 'another-reg',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $this->assertCount(2, $this->service->getSequencesByTrigger('user_registration'));
    }
}
