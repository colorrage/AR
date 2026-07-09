<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Commands;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class CommandsTest extends TestCase
{
    public function test_process_enrollments_runs(): void
    {
        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'S', 'slug' => 'cmd-seq',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);
        Enrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'due@example.com',
            'state' => 'active', 'next_run_at' => now()->subHour(),
            'enrolled_at' => now()->subHour(), 'dedupe_key' => 'cmd-due',
        ]);

        $exitCode = Artisan::call('autoresponder:process-enrollments');
        $this->assertEquals(0, $exitCode);
    }

    public function test_process_enrollments_with_options(): void
    {
        $exitCode = Artisan::call('autoresponder:process-enrollments', ['--limit' => 10]);
        $this->assertEquals(0, $exitCode);
        $exitCode = Artisan::call('autoresponder:process-enrollments', ['--dry-run' => true]);
        $this->assertEquals(0, $exitCode);
    }

    public function test_check_triggers_runs(): void
    {
        $exitCode = Artisan::call('autoresponder:check-triggers');
        $this->assertEquals(0, $exitCode);
    }

    public function test_process_scheduled_campaigns_runs(): void
    {
        $exitCode = Artisan::call('autoresponder:process-scheduled-campaigns');
        $this->assertEquals(0, $exitCode);
    }
}
