<?php

namespace ColorrageAR\Autoresponder\Events;

use ColorrageAR\Autoresponder\Contracts\Subscribable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ListSubscribed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscribable $subscriber,
        public int $listId,
        public string $email
    ) {}
}
