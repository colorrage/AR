<?php

namespace ColorrageAR\Autoresponder\Events;

use ColorrageAR\Autoresponder\Contracts\Subscribable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscribable $subscriber,
        public array $paymentData = []
    ) {}
}
