<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Services\AutoresponderService;
use ColorrageAR\Autoresponder\Tests\Support\SubscriberMocker;
use ColorrageAR\Autoresponder\Tests\TestCase;

class EnrollmentWorkflowTest extends TestCase
{
    public function test_full_enrollment_flow(): void
    {
        $template = Template::create([
            'name' => 'Welcome', 'subject' => 'Welcome ##subscriber.name##',
            'body' => '<p>Hello</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'Full Flow', 'slug' => 'full-flow',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);

        $subscriber = SubscriberMocker::default();
        $service = app(AutoresponderService::class);
        $enrollments = $service->enroll('user_registration', $subscriber);

        $this->assertCount(1, $enrollments);
        $this->assertEquals($subscriber->getSubscribableEmail(), $enrollments->first()->email);
    }

    public function test_dedup_prevents_duplicate_enrollment(): void
    {
        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'Dedup S', 'slug' => 'dedup-s',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);

        $subscriber = SubscriberMocker::default();
        $service = app(AutoresponderService::class);

        $first = $service->enroll('user_registration', $subscriber);
        $second = $service->enroll('user_registration', $subscriber);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertEquals(1, Enrollment::count());
    }

    public function test_unsubscribed_user_cannot_enroll(): void
    {
        $email = 'unsub-enroll@example.com';
        Unsubscribe::create(['email' => $email]);

        $template = Template::create([
            'name' => 'Tpl', 'subject' => 'Hello', 'body' => '<p>Hi</p>', 'locale' => 'en',
        ]);
        $sequence = Sequence::create([
            'name' => 'No Enroll', 'slug' => 'no-enroll',
            'trigger_type' => 'user_registration', 'status' => 'active', 'default_locale' => 'en',
        ]);
        Step::create([
            'sequence_id' => $sequence->id, 'step_number' => 1,
            'name' => 'Step 1', 'status' => 'active', 'template_id' => $template->id,
        ]);

        $subscriber = (new SubscriberMocker)->withEmail($email)->make();
        $service = app(AutoresponderService::class);
        $result = $service->enroll('user_registration', $subscriber);

        $this->assertCount(0, $result);
    }
}
