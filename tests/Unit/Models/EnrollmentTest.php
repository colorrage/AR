<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use Carbon\Carbon;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Tests\TestCase;

class EnrollmentTest extends TestCase
{
    private Sequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sequence = Sequence::create([
            'name' => 'Test Sequence',
            'slug' => 'test-sequence',
            'status' => 'active',
            'trigger_type' => 'user_registration',
            'default_locale' => 'en',
        ]);

        Step::create([
            'sequence_id' => $this->sequence->id,
            'step_number' => 1,
            'name' => 'Step 1',
            'status' => 'active',
        ]);

        Step::create([
            'sequence_id' => $this->sequence->id,
            'step_number' => 2,
            'name' => 'Step 2',
            'status' => 'active',
        ]);

        Step::create([
            'sequence_id' => $this->sequence->id,
            'step_number' => 3,
            'name' => 'Step 3',
            'status' => 'active',
        ]);
    }

    public function test_enrollment_is_active_on_creation(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $this->assertTrue($enrollment->isActive());
        $this->assertEquals('active', $enrollment->state);
    }

    public function test_pause_and_resume(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $enrollment->pause();
        $this->assertEquals('paused', $enrollment->fresh()->state);

        $enrollment->resume();
        $this->assertEquals('active', $enrollment->fresh()->state);
    }

    public function test_complete(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $enrollment->complete();
        $this->assertEquals('completed', $enrollment->fresh()->state);
        $this->assertNotNull($enrollment->fresh()->completed_at);
    }

    public function test_exit_with_reason(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $enrollment->exit('Manual cancel');
        $this->assertEquals('exited', $enrollment->fresh()->state);
    }

    public function test_mark_unsubscribed(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $enrollment->markUnsubscribed();
        $enr = $enrollment->fresh();
        $this->assertEquals('unsubscribed', $enr->state);
        $this->assertEquals('Subscriber unsubscribed', $enr->exit_reason);
    }

    public function test_mark_failed(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $enrollment->markFailed('SMTP error');
        $enr = $enrollment->fresh();
        $this->assertEquals('failed', $enr->state);
        $this->assertEquals('SMTP error', $enr->exit_reason);
    }

    public function test_advance(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $enrollment->advance(1);
        $this->assertEquals(1, $enrollment->fresh()->current_step_number);
    }

    public function test_can_resume(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $this->assertFalse($enrollment->canResume());
        $enrollment->pause();
        $this->assertTrue($enrollment->fresh()->canResume());
    }

    public function test_scope_active(): void
    {
        Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'active@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'key1',
        ]);

        Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'completed@example.com',
            'state' => 'completed',
            'enrolled_at' => now(),
            'dedupe_key' => 'key2',
        ]);

        $this->assertCount(1, Enrollment::active()->get());
    }

    public function test_scope_ready_to_process(): void
    {
        Carbon::setTestNow(Carbon::now());

        Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'due@example.com',
            'state' => 'active',
            'next_run_at' => now()->subHour(),
            'enrolled_at' => now()->subHour(),
            'dedupe_key' => 'key1',
        ]);

        Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'future@example.com',
            'state' => 'active',
            'next_run_at' => now()->addHour(),
            'enrolled_at' => now(),
            'dedupe_key' => 'key2',
        ]);

        $this->assertCount(1, Enrollment::readyToProcess()->get());

        Carbon::setTestNow(null);
    }

    public function test_scope_by_state(): void
    {
        Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'a@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'key1',
        ]);

        Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'b@example.com',
            'state' => 'paused',
            'enrolled_at' => now(),
            'dedupe_key' => 'key2',
        ]);

        $this->assertCount(1, Enrollment::byState('active')->get());
        $this->assertCount(1, Enrollment::byState('paused')->get());
    }

    public function test_progress_percentage(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $this->assertIsFloat($enrollment->progress_percentage);
    }

    public function test_computed_attributes(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $this->assertEquals('success', $enrollment->state_color);
        $this->assertEquals('Active', $enrollment->state_display);
        $this->assertEquals(0, $enrollment->sent_emails_count);
    }

    public function test_relationship_with_sequence(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $this->assertEquals('Test Sequence', $enrollment->sequence->name);
    }

    public function test_has_completed_all_steps(): void
    {
        $enrollment = Enrollment::create([
            'sequence_id' => $this->sequence->id,
            'email' => 'test@example.com',
            'current_step_number' => 3,
            'state' => 'active',
            'enrolled_at' => now(),
            'dedupe_key' => 'test-key',
        ]);

        $this->assertTrue($enrollment->hasCompletedAllSteps());
    }
}
