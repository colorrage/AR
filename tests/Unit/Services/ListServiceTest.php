<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Services;

use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Services\ListService;
use ColorrageAR\Autoresponder\Tests\TestCase;

class ListServiceTest extends TestCase
{
    private ListService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ListService::class);
    }

    public function test_get_subscribers_empty_static_list(): void
    {
        $list = MailerList::create([
            'name' => 'Empty List',
            'type' => 'manual',
        ]);

        $subscribers = $this->service->getSubscribers($list);

        $this->assertCount(0, $subscribers);
    }

    public function test_add_subscriber_to_list(): void
    {
        $list = MailerList::create([
            'name' => 'Test List',
            'type' => 'manual',
        ]);

        $this->service->addSubscriber($list, 'test@example.com', 'Test User');

        $subscribers = $this->service->getSubscribers($list);
        $this->assertCount(1, $subscribers);
    }

    public function test_add_duplicate_subscriber_is_upsert(): void
    {
        $list = MailerList::create([
            'name' => 'Dup List',
            'type' => 'manual',
        ]);

        $this->service->addSubscriber($list, 'test@example.com', 'Old Name');
        $this->service->addSubscriber($list, 'test@example.com', 'New Name');

        $this->assertEquals(1, $this->service->getSubscriberCount($list));
    }

    public function test_remove_subscriber(): void
    {
        $list = MailerList::create([
            'name' => 'Removal List',
            'type' => 'manual',
        ]);

        $this->service->addSubscriber($list, 'test@example.com');
        $this->assertEquals(1, $this->service->getSubscriberCount($list));

        $this->service->removeSubscriber($list, 'test@example.com');
        $this->assertEquals(0, $this->service->getSubscriberCount($list));
    }

    public function test_get_subscriber_count(): void
    {
        $list = MailerList::create([
            'name' => 'Count List',
            'type' => 'manual',
        ]);

        $this->service->addSubscriber($list, 'a@example.com');
        $this->service->addSubscriber($list, 'b@example.com');

        $this->assertEquals(2, $this->service->getSubscriberCount($list));
    }
}
