<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Jobs;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Bus;

class SendStepEmailTest extends TestCase
{
    public function test_job_handles_missing_enrollment(): void
    {
        Mail::fake();
        $job = new \ColorrageAR\Autoresponder\Jobs\SendStepEmail(99999, 99999);
        $job->handle(
            app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class),
            app(\ColorrageAR\Autoresponder\Services\TokenService::class)
        );
        Mail::assertNothingSent();
    }

    public function test_job_skips_when_sequence_inactive(): void
    {
        Mail::fake();
        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'Draft', 'slug' => 'draft-seq',
            'trigger_type' => 'user_registration', 'status' => 'draft', 'default_locale' => 'en',
        ]);
        $step = Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
            'condition_type' => 'opened_previous',
        ]);
        $enrollment = Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'test@example.com',
            'state' => 'active', 'enrolled_at' => now(), 'dedupe_key' => 'dk-cond',
        ]);
        $job = new \ColorrageAR\Autoresponder\Jobs\SendStepEmail($enrollment->id, $step->id);
        $job->handle(
            app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class),
            app(\ColorrageAR\Autoresponder\Services\TokenService::class)
        );
        Mail::assertNothingSent();
    }

    public function test_job_sends_email(): void
    {
        Mail::fake();
        $template = Template::create([
            'name' => 'Welcome', 'subject' => 'Welcome ##subscriber.name##',
            'body' => '<p>Hello ##subscriber.name##</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'Welcome Series', 'slug' => 'welcome-send',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        $step = Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);
        $enrollment = Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'test@example.com',
            'trigger_data' => json_encode(['name' => 'John']),
            'state' => 'active', 'enrolled_at' => now(), 'dedupe_key' => 'dk-send',
        ]);
        $job = new \ColorrageAR\Autoresponder\Jobs\SendStepEmail($enrollment->id, $step->id);
        $job->handle(
            app(\ColorrageAR\Autoresponder\Services\AutoresponderService::class),
            app(\ColorrageAR\Autoresponder\Services\TokenService::class)
        );
        Mail::assertSentCount(1);
        $this->assertEquals(1, $enrollment->fresh()->current_step_number);
    }
}
