<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Tests\TestCase;

class StepTest extends TestCase
{
    private Sequence $sequence;
    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::create([
            'name' => 'Test Template',
            'subject' => 'Hello',
            'body' => '<p>Body</p>',
            'locale' => 'en',
        ]);

        $this->sequence = Sequence::create([
            'name' => 'Step Test', 'slug' => 'step-test',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
    }

    public function test_get_delay_in_seconds(): void
    {
        $step = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 1,
            'name' => 'Delayed', 'status' => 'active',
            'delay_value' => 5, 'delay_unit' => 'minutes',
        ]);

        $this->assertEquals(300, $step->getDelayInSeconds());
    }

    public function test_delay_in_hours_days_weeks(): void
    {
        $hourly = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 1,
            'name' => 'Hourly', 'status' => 'active',
            'delay_value' => 2, 'delay_unit' => 'hours',
        ]);
        $this->assertEquals(7200, $hourly->getDelayInSeconds());

        $daily = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 2,
            'name' => 'Daily', 'status' => 'active',
            'delay_value' => 3, 'delay_unit' => 'days',
        ]);
        $this->assertEquals(259200, $daily->getDelayInSeconds());
    }

    public function test_calculate_send_time(): void
    {
        $triggerTime = \Carbon\Carbon::now();

        $step = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 3,
            'name' => 'Timed', 'status' => 'active',
            'delay_value' => 30, 'delay_unit' => 'minutes', 'delay_type' => 'from_trigger',
        ]);

        $sendTime = $step->calculateSendTime($triggerTime);
        $this->assertEquals($triggerTime->timestamp + 1800, $sendTime->timestamp);
    }

    public function test_is_first_and_last_step(): void
    {
        Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 1,
            'name' => 'S1', 'status' => 'active',
        ]);
        Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 2,
            'name' => 'S2', 'status' => 'active',
        ]);
        $step3 = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 3,
            'name' => 'S3', 'status' => 'active',
        ]);

        $this->assertTrue($step3->isLastStep());
    }

    public function test_get_next_step(): void
    {
        $s1 = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 1,
            'name' => 'S1', 'status' => 'active',
        ]);
        Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 2,
            'name' => 'S2', 'status' => 'active',
        ]);

        $next = $s1->getNextStep();
        $this->assertNotNull($next);
        $this->assertEquals(2, $next->step_number);
    }

    public function test_has_conditions(): void
    {
        $step = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 4,
            'name' => 'Conditional', 'status' => 'active',
            'condition_type' => 'opened_previous',
        ]);

        $this->assertTrue($step->hasConditions());
    }

    public function test_computed_attributes(): void
    {
        $step = Step::create([
            'sequence_id' => $this->sequence->id, 'step_number' => 5,
            'name' => 'Stats Step', 'status' => 'active',
            'delay_value' => 1, 'delay_unit' => 'hours', 'delay_type' => 'from_prev_step',
        ]);

        $this->assertStringContainsString('1 hour', $step->delay_display);

        $stats = $step->stats;
        $this->assertArrayHasKey('sent', $stats);
        $this->assertEquals(0, $stats['sent']);
    }
}
