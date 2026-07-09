<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Events;

use ColorrageAR\Autoresponder\Events\SubscriberRegistered;
use ColorrageAR\Autoresponder\Events\SubscriberLoggedIn;
use ColorrageAR\Autoresponder\Events\PaymentSucceeded;
use ColorrageAR\Autoresponder\Events\PaymentFailed;
use ColorrageAR\Autoresponder\Events\ListSubscribed;
use ColorrageAR\Autoresponder\Events\CustomTrigger;
use ColorrageAR\Autoresponder\Listeners\TriggerListener;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Tests\Support\SubscriberMocker;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class TriggerListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);

        $sequence = Sequence::create([
            'name' => 'Event Seq', 'slug' => 'event-seq',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);

        Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);
    }

    public function test_trigger_listener_subscribe_returns_event_map(): void
    {
        $listener = app(TriggerListener::class);
        $events = $listener->subscribe(collect());

        $this->assertArrayHasKey(SubscriberRegistered::class, $events);
        $this->assertArrayHasKey(SubscriberLoggedIn::class, $events);
        $this->assertArrayHasKey(PaymentSucceeded::class, $events);
        $this->assertArrayHasKey(PaymentFailed::class, $events);
        $this->assertArrayHasKey(ListSubscribed::class, $events);
        $this->assertArrayHasKey(CustomTrigger::class, $events);
    }

    public function test_handle_subscriber_registered(): void
    {
        Event::fake();
        $subscriber = SubscriberMocker::default();
        event(new SubscriberRegistered($subscriber));
        Event::assertDispatched(SubscriberRegistered::class);
    }

    public function test_handle_custom_trigger(): void
    {
        Event::fake();
        $subscriber = SubscriberMocker::default();
        event(new CustomTrigger('payment_success', $subscriber, ['amount' => 100]));
        Event::assertDispatched(CustomTrigger::class);
    }
}
