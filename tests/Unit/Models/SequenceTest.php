<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Tests\TestCase;

class SequenceTest extends TestCase
{
    public function test_sequence_is_created_with_slug(): void
    {
        $seq = Sequence::create([
            'name' => 'My Welcome Series',
            'trigger_type' => 'user_registration',
            'status' => 'active',
            'default_locale' => 'en',
        ]);

        $this->assertEquals('my-welcome-series', $seq->slug);
    }

    public function test_scope_active(): void
    {
        Sequence::create([
            'name' => 'Active Seq', 'slug' => 'active-seq',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Sequence::create([
            'name' => 'Draft Seq', 'slug' => 'draft-seq',
            'trigger_type' => 'user_registration', 'status' => 'draft', 'default_locale' => 'en',
        ]);

        $this->assertCount(1, Sequence::active()->get());
    }

    public function test_scope_by_trigger(): void
    {
        Sequence::create([
            'name' => 'Reg Seq', 'slug' => 'reg-seq',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Sequence::create([
            'name' => 'Pay Seq', 'slug' => 'pay-seq',
            'trigger_type' => 'payment_success', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $this->assertCount(1, Sequence::byTrigger('user_registration')->get());
        $this->assertCount(1, Sequence::byTrigger('payment_success')->get());
    }

    public function test_scope_order_by_priority(): void
    {
        Sequence::create([
            'name' => 'Low', 'slug' => 'low',
            'trigger_type' => 'user_registration', 'status' => 'active', 'priority' => 10, 'default_locale' => 'en',
        ]);
        Sequence::create([
            'name' => 'High', 'slug' => 'high',
            'trigger_type' => 'user_registration', 'status' => 'active', 'priority' => 1, 'default_locale' => 'en',
        ]);

        $ordered = Sequence::orderByPriority()->get();
        $this->assertEquals('High', $ordered->first()->name);
    }

    public function test_scope_by_status(): void
    {
        Sequence::create([
            'name' => 'Paused', 'slug' => 'paused',
            'trigger_type' => 'user_registration', 'status' => 'paused', 'default_locale' => 'en',
        ]);

        $this->assertCount(1, Sequence::byStatus('paused')->get());
    }

    public function test_is_active(): void
    {
        $seq = Sequence::create([
            'name' => 'Active Seq', 'slug' => 'active-seq-2',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $this->assertTrue($seq->isActive());
    }

    public function test_is_draft(): void
    {
        $seq = Sequence::create([
            'name' => 'Draft', 'slug' => 'draft-2',
            'trigger_type' => 'user_registration', 'status' => 'draft', 'default_locale' => 'en',
        ]);
        $this->assertTrue($seq->isDraft());
    }

    public function test_is_paused(): void
    {
        $seq = Sequence::create([
            'name' => 'Paused', 'slug' => 'paused-2',
            'trigger_type' => 'user_registration', 'status' => 'paused', 'default_locale' => 'en',
        ]);
        $this->assertTrue($seq->isPaused());
    }

    public function test_quiet_hours_not_set(): void
    {
        $seq = Sequence::create([
            'name' => 'No QH', 'slug' => 'no-qh',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $this->assertFalse($seq->isInQuietHours());
    }

    public function test_throttle_when_not_set(): void
    {
        $seq = Sequence::create([
            'name' => 'No Throttle', 'slug' => 'no-throttle',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $this->assertFalse($seq->isThrottled());
    }

    public function test_computed_attributes(): void
    {
        $seq = Sequence::create([
            'name' => 'Test Seq', 'slug' => 'test-seq',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $this->assertEquals('success', $seq->status_color);
        $this->assertEquals(0, $seq->active_enrollments_count);
        $this->assertEquals(0, $seq->total_enrollments_count);
        $this->assertEquals(0.0, $seq->completion_rate);
    }

    public function test_trigger_type_display(): void
    {
        $seq = Sequence::create([
            'name' => 'Reg Seq', 'slug' => 'reg-seq-2',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        $this->assertEquals('User Registration', $seq->trigger_type_display);
    }

    public function test_completion_rate_with_data(): void
    {
        $seq = Sequence::create([
            'name' => 'With Data', 'slug' => 'with-data',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        Enrollment::create([
            'sequence_id' => $seq->id, 'email' => 'one@example.com',
            'state' => 'completed', 'enrolled_at' => now(), 'dedupe_key' => 'dk1',
        ]);
        Enrollment::create([
            'sequence_id' => $seq->id, 'email' => 'two@example.com',
            'state' => 'active', 'enrolled_at' => now(), 'dedupe_key' => 'dk2',
        ]);

        $this->assertEquals(50.0, $seq->completion_rate);
    }
}
