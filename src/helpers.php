<?php

namespace CmrManagement\Autoresponder;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

function ar_table(string $name): string
{
    return config('autoresponder.table_prefix', 'ar_') . $name;
}

function ar_log(): LoggerInterface
{
    $channel = config('autoresponder.log_channel');

    return $channel ? Log::channel($channel) : Log::getLogger();
}

function ar_queue(): string
{
    return config('autoresponder.queue', 'emails');
}

function ar_subscriber_model(): string
{
    return config('autoresponder.subscriber_model', 'App\\Models\\User');
}

function ar_subscriber_key(): string
{
    return config('autoresponder.subscriber_columns.key', 'id');
}
