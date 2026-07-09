<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Jobs;

use ColorrageAR\Autoresponder\Jobs\ProcessEnrollment;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Bus;

class ProcessEnrollmentTest extends TestCase
{
    public function test_job_handles_nonexistent_enrollment(): void
    {
        $job = new ProcessEnrollment(99999);
        $job->handle(app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class));
        $this->assertTrue(true);
    }

    public function test_job_completes_when_no_more_steps(): void
    {
        $sequence = Sequence::create([
            'name' => 'No Steps', 'slug' => 'no-steps',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $enrollment = Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'test@example.com',
            'current_step_number' => 1, 'state' => 'active',
            'enrolled_at' => now(), 'dedupe_key' => 'dk-no-steps',
        ]);
        $job = new ProcessEnrollment($enrollment->id);
        $job->handle(app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class));
        $this->assertEquals('completed', $enrollment->fresh()->state);
    }

    public function test_job_dispatches_send_step_email(): void
    {
        Bus::fake();
        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'With Steps', 'slug' => 'with-steps',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);
        $enrollment = Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'test@example.com',
            'current_step_number' => 0, 'state' => 'active',
            'enrolled_at' => now(), 'dedupe_key' => 'dk-dispatch',
        ]);
        $job = new ProcessEnrollment($enrollment->id);
        $job->handle(app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class));
        Bus::assertDispatched(\ColorrageAR\Autoresponder\Jobs\SendStepEmail::class);
    }

    public function test_job_skips_inactive_enrollment(): void
    {
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 's-inactive',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $enrollment = Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'test@example.com',
            'state' => 'paused', 'enrolled_at' => now(), 'dedupe_key' => 'dk-inactive',
        ]);
        $job = new ProcessEnrollment($enrollment->id);
        $job->handle(app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class));
        $this->assertEquals('paused', $enrollment->fresh()->state);
    }
}
