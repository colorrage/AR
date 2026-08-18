<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Tests\TestCase;

class MailerListTest extends TestCase
{
    public function test_list_subscribers_are_scoped_to_the_mailer_list(): void
    {
        $list = MailerList::create(['name' => 'Product updates', 'type' => 'manual']);
        $otherList = MailerList::create(['name' => 'Events', 'type' => 'manual']);

        $list->listSubscribers()->create([
            'email' => 'member@example.com',
            'status' => 'active',
        ]);
        ListSubscriber::create([
            'list_id' => $otherList->id,
            'email' => 'other@example.com',
            'status' => 'active',
        ]);

        $this->assertSame(['member@example.com'], $list->listSubscribers()->pluck('email')->all());
    }
}
