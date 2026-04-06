<?php

namespace CmrManagement\Autoresponder\Contracts;

use CmrManagement\Autoresponder\Models\Sequence;
use Illuminate\Support\Collection;

interface TriggerHandler
{
    /**
     * Return a collection of Subscribable instances that match
     * this trigger's criteria for the given sequence.
     */
    public function getSubscribers(Sequence $sequence): Collection;
}
