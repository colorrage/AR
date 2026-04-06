<?php

namespace CmrManagement\Autoresponder\Events;

use CmrManagement\Autoresponder\Contracts\Subscribable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomTrigger
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $triggerType,
        public Subscribable $subscriber,
        public array $data = []
    ) {}
}
