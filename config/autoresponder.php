<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscriber Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model that represents your users/subscribers.
    | Must implement CmrManagement\Autoresponder\Contracts\Subscribable.
    | Defaults to Laravel's User model.
    |
    */
    'subscriber_model' => env('AUTORESPONDER_SUBSCRIBER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Subscriber Model Column Mapping
    |--------------------------------------------------------------------------
    |
    | Map the subscriber model's column names. This allows the package
    | to work with any user table schema.
    |
    */
    'subscriber_columns' => [
        'key' => 'id',
        'email' => 'email',
        'name' => 'name',
        'locale' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | All package tables will be prefixed with this value.
    | Default: 'ar_' → ar_sequences, ar_steps, ar_enrollments, etc.
    |
    */
    'table_prefix' => env('AUTORESPONDER_TABLE_PREFIX', 'ar_'),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | URL prefix for tracking pixels, click tracking, and unsubscribe routes.
    |
    */
    'route_prefix' => env('AUTORESPONDER_ROUTE_PREFIX', 'autoresponder'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the package's web routes.
    |
    */
    'route_middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Queue name for all email sending and processing jobs.
    |
    */
    'queue' => env('AUTORESPONDER_QUEUE', 'emails'),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | Log channel for autoresponder activity. Set to null to use the default.
    |
    */
    'log_channel' => env('AUTORESPONDER_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Control email sending speed to avoid SMTP blacklisting.
    |
    */
    'rate_limit' => [
        'emails_per_batch' => 2,
        'batch_delay_seconds' => 30,
        'max_emails_per_campaign' => 3000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Queue job retry settings for failed emails.
    |
    */
    'retry' => [
        'max_attempts' => 3,
        'backoff' => [60, 300, 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Definitions
    |--------------------------------------------------------------------------
    |
    | Define tokens for template replacement. Format: ##token.name##
    | 'subscriber' tokens map to the Subscribable model attributes.
    | 'config' tokens map to Laravel config values.
    | 'custom' tokens can be resolved by a callback class.
    |
    */
    'tokens' => [
        'subscriber' => [
            'name' => 'name',
            'email' => 'email',
        ],
        'config' => [
            'app.name' => 'Application Name',
            'app.url' => 'Application URL',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Token Resolver
    |--------------------------------------------------------------------------
    |
    | Optional class that implements TokenResolverInterface to handle
    | custom token replacement (e.g., ##custom.premium_status##).
    |
    */
    'custom_token_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Trigger Types
    |--------------------------------------------------------------------------
    |
    | Built-in trigger types for autoresponder sequences.
    | Host app can extend this list.
    |
    */
    'trigger_types' => [
        'user_registration' => 'User Registration',
        'first_login' => 'First Login',
        'payment_success' => 'Payment Success',
        'payment_failed' => 'Payment Failed',
        'subscription_renewal' => 'Subscription Renewal',
        'subscription_expiring' => 'Subscription Expiring',
        'account_inactive' => 'Account Inactive',
        'mailing_list' => 'Mailing List Subscription',
        'manual' => 'Manual Trigger',
        'date_based' => 'Date Based',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Condition Checkers
    |--------------------------------------------------------------------------
    |
    | Register custom step condition checkers.
    | Key = condition_type string, Value = class implementing ConditionChecker.
    |
    | Example:
    |   'still_free' => App\Autoresponder\Conditions\StillFreeCondition::class,
    |
    */
    'conditions' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom Stop Event Checkers
    |--------------------------------------------------------------------------
    |
    | Register custom stop-on-event checkers.
    | Key = event string, Value = class implementing StopEventChecker.
    |
    | Example:
    |   'first_purchase' => App\Autoresponder\StopEvents\FirstPurchaseStop::class,
    |
    */
    'stop_events' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom Trigger Handlers
    |--------------------------------------------------------------------------
    |
    | Register custom trigger handlers for time-based triggers.
    | Key = trigger_type, Value = class implementing TriggerHandler.
    | Used by the autoresponder:check-triggers command.
    |
    */
    'trigger_handlers' => [],

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    |
    | Languages available for email templates and unsubscribe pages.
    |
    */
    'languages' => [
        'en' => ['name' => 'English', 'flag' => 'gb'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Unsubscribe Translations
    |--------------------------------------------------------------------------
    |
    | Per-language text for the unsubscribe footer appended to emails.
    |
    */
    'unsubscribe_translations' => [
        'en' => [
            'main' => 'If you no longer wish to receive these emails, you can',
            'link' => 'unsubscribe here',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail Settings
    |--------------------------------------------------------------------------
    */
    'mail' => [
        'from_address' => env('AUTORESPONDER_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('AUTORESPONDER_FROM_NAME', env('MAIL_FROM_NAME')),
        'test_email' => env('AUTORESPONDER_TEST_EMAIL'),
    ],

];
