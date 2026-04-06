<?php

namespace CmrManagement\Autoresponder\Events;

use CmrManagement\Autoresponder\Contracts\Subscribable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriberLoggedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscribable $subscriber
    ) {}
}
