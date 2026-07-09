# Laravel Autoresponder

Full email marketing suite for Laravel — autoresponder sequences, broadcast campaigns, mailer lists, and analytics built on top of Eloquent and queues.

## Features

- **Autoresponder sequences** with multi-step drip campaigns and configurable delays
- **A/B testing** per step — split variants with automatic winner selection
- **Broadcast email campaigns** with scheduling and recurring sends
- **Mailer lists** — manual (tag-based) and dynamic (query-based) subscriber lists
- **Email templates** with token replacement (subscriber fields, custom data, dates)
- **Open/click tracking** — tracking pixel for opens, link rewriting for clicks
- **Unsubscribe management** — one-click unsubscribe with per-sequence and global options
- **Quiet hours + throttling** — respect sending windows and rate limits
- **Configurable user model** — works with any Eloquent model, not just `User`
- **Queue-based sending** with retry logic and failure tracking
- **Extensible conditions, stop events, and trigger handlers** — plug in your own business logic
- **Multi-language support** — send emails in the subscriber's preferred language

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- A queue driver (database, Redis, SQS, etc.)

## Installation

```bash
composer require cmr-management/laravel-autoresponder
```

Publish the config file:

```bash
php artisan vendor:publish --tag=autoresponder-config
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=autoresponder-migrations
php artisan migrate
```

Optionally publish the views to customize email templates:

```bash
php artisan vendor:publish --tag=autoresponder-views
```

## Configuration

After publishing, edit `config/autoresponder.php`. Key options:

### Subscriber model

Point to whatever Eloquent model represents your subscribers:

```php
'subscriber_model' => \App\Models\User::class,

'subscriber_columns' => [
    'email'      => 'email',
    'name'       => 'name',
    'language'   => 'locale',      // column storing the subscriber's language
    'subscribed' => 'is_subscribed', // boolean opt-in column (null to skip check)
],
```

### Table prefix

All package tables are prefixed to avoid collisions:

```php
'table_prefix' => 'ar_',
```

This produces tables like `ar_sequences`, `ar_steps`, `ar_enrollments`, etc.

### Route prefix

```php
'route_prefix' => 'autoresponder',
```

Tracking and unsubscribe endpoints will be served under this prefix (e.g. `/autoresponder/track/open/{id}`).

### Queue

```php
'queue' => [
    'connection' => env('AUTORESPONDER_QUEUE_CONNECTION', null), // null = default
    'name'       => env('AUTORESPONDER_QUEUE', 'autoresponder'),
],
```

### Sending limits

```php
'throttle' => [
    'per_minute' => 60,
    'per_hour'   => 2000,
],

'quiet_hours' => [
    'enabled'  => true,
    'start'    => '22:00',
    'end'      => '07:00',
    'timezone' => 'UTC',
],
```

### Tokens

Built-in token resolvers and custom resolvers are registered here:

```php
'tokens' => [
    'resolvers' => [
        // 'company' => \App\TokenResolvers\CompanyTokenResolver::class,
    ],
],
```

### Languages

```php
'languages' => ['en', 'ro', 'de', 'fr', 'it', 'es'],

'default_language' => 'en',
```

## Quick Start

### 1. Prepare your subscriber model

Add the `Subscribable` interface and `HasSubscriptions` trait to your User (or any model):

```php
use ColorrageAR\Autoresponder\Contracts\Subscribable;
use ColorrageAR\Autoresponder\Traits\HasSubscriptions;

class User extends Authenticatable implements Subscribable
{
    use HasSubscriptions;

    // The trait provides:
    // - enrollments()
    // - activeEnrollments()
    // - isEnrolledIn(Sequence $sequence)
    // - subscriberEmail(): string
    // - subscriberName(): string
    // - subscriberLanguage(): string
}
```

### 2. Create a sequence

```php
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Step;

$sequence = Sequence::create([
    'name'         => 'Welcome Series',
    'trigger_type' => 'registration',
    'is_active'    => true,
]);

// Step 1: send immediately after enrollment
$sequence->steps()->create([
    'order'        => 1,
    'delay_value'  => 0,
    'delay_unit'   => 'minutes',
    'subject'      => 'Welcome to {{app_name}}!',
    'body'         => '<p>Hi {{first_name}}, thanks for signing up.</p>',
]);

// Step 2: send 24 hours later
$sequence->steps()->create([
    'order'        => 2,
    'delay_value'  => 24,
    'delay_unit'   => 'hours',
    'subject'      => 'Getting started guide',
    'body'         => '<p>Here are some tips to get started...</p>',
]);
```

### 3. Enroll a subscriber

Programmatically:

```php
use ColorrageAR\Autoresponder\Facades\Autoresponder;

Autoresponder::enroll($user, $sequence);
```

Or dispatch the registration event so the trigger system picks it up automatically:

```php
use ColorrageAR\Autoresponder\Events\SubscriberRegistered;

event(new SubscriberRegistered($user));
```

### 4. Schedule processing

Add to your `app/Console/Kernel.php`:

```php
$schedule->command('autoresponder:process-enrollments')->everyMinute();
$schedule->command('autoresponder:check-triggers')->everyFiveMinutes();
$schedule->command('autoresponder:process-campaigns')->everyMinute();
```

## Events

The package dispatches and listens to these events:

| Event | Fired when |
|-------|------------|
| `SubscriberRegistered` | A new subscriber is created |
| `SubscriberEnrolled` | A subscriber is enrolled in a sequence |
| `SubscriberUnenrolled` | A subscriber is removed from a sequence |
| `StepSent` | A sequence step email is sent |
| `StepOpened` | A tracking pixel is loaded |
| `StepClicked` | A tracked link is clicked |
| `CampaignSent` | A broadcast campaign finishes sending |
| `SubscriberUnsubscribed` | A subscriber opts out |

### Dispatching custom trigger events

```php
use ColorrageAR\Autoresponder\Events\CustomTriggerFired;

// Trigger sequences that listen for 'first_purchase'
event(new CustomTriggerFired('first_purchase', $user, [
    'order_id' => $order->id,
]));
```

## Custom Conditions

Conditions control whether a step is sent. Implement `ConditionChecker`:

```php
use ColorrageAR\Autoresponder\Contracts\ConditionChecker;

class HasActiveSubscription implements ConditionChecker
{
    public function check($subscriber, array $params = []): bool
    {
        return $subscriber->subscription_status === 'active';
    }
}
```

Register in config:

```php
'conditions' => [
    'has_active_subscription' => \App\Conditions\HasActiveSubscription::class,
],
```

Then reference it in a step:

```php
$step->update([
    'conditions' => [
        ['type' => 'has_active_subscription'],
    ],
]);
```

## Custom Stop Events

Stop events unenroll a subscriber from a sequence when a condition is met. Implement `StopEventChecker`:

```php
use ColorrageAR\Autoresponder\Contracts\StopEventChecker;

class SubscriberUpgraded implements StopEventChecker
{
    public function shouldStop($subscriber, array $params = []): bool
    {
        return $subscriber->plan === 'premium';
    }
}
```

Register in config:

```php
'stop_events' => [
    'subscriber_upgraded' => \App\StopEvents\SubscriberUpgraded::class,
],
```

## Custom Triggers

Triggers control when a subscriber is enrolled in a sequence. Implement `TriggerHandler`:

```php
use ColorrageAR\Autoresponder\Contracts\TriggerHandler;
use ColorrageAR\Autoresponder\Models\Sequence;

class InactivityTrigger implements TriggerHandler
{
    public function shouldEnroll($subscriber, Sequence $sequence, array $params = []): bool
    {
        return $subscriber->last_login_at < now()->subDays(30);
    }

    public function getSubscribersToCheck(Sequence $sequence): \Illuminate\Support\Collection
    {
        return User::where('last_login_at', '<', now()->subDays(30))->get();
    }
}
```

Register in config:

```php
'triggers' => [
    'inactivity_30_days' => \App\Triggers\InactivityTrigger::class,
],
```

## Token Replacement

Built-in tokens available in subjects and bodies:

| Token | Resolves to |
|-------|-------------|
| `{{first_name}}` | Subscriber's first name |
| `{{last_name}}` | Subscriber's last name |
| `{{email}}` | Subscriber's email |
| `{{app_name}}` | Application name from config |
| `{{unsubscribe_url}}` | One-click unsubscribe link |
| `{{tracking_pixel}}` | Open-tracking pixel (auto-injected) |
| `{{current_date}}` | Current date in subscriber's locale |

### Custom token resolver

```php
use ColorrageAR\Autoresponder\Contracts\TokenResolver;

class CompanyTokenResolver implements TokenResolver
{
    public function tokens($subscriber): array
    {
        return [
            'company_name'    => $subscriber->company?->name ?? '',
            'company_plan'    => $subscriber->company?->plan ?? 'free',
            'remaining_quota' => $subscriber->company?->quota_remaining ?? 0,
        ];
    }
}
```

Register in config under `tokens.resolvers`:

```php
'tokens' => [
    'resolvers' => [
        'company' => \App\TokenResolvers\CompanyTokenResolver::class,
    ],
],
```

## Artisan Commands

### `autoresponder:process-enrollments`

Processes pending enrollments — sends any steps that are due based on delay timers.

```bash
php artisan autoresponder:process-enrollments
php artisan autoresponder:process-enrollments --limit=500   # process max 500
php artisan autoresponder:process-enrollments --sequence=3  # only sequence ID 3
```

### `autoresponder:check-triggers`

Evaluates time-based and custom triggers, enrolling subscribers who match.

```bash
php artisan autoresponder:check-triggers
php artisan autoresponder:check-triggers --trigger=inactivity_30_days  # specific trigger only
```

### `autoresponder:process-campaigns`

Sends scheduled broadcast campaigns that are due.

```bash
php artisan autoresponder:process-campaigns
php artisan autoresponder:process-campaigns --campaign=12  # specific campaign only
```

### `autoresponder:import-templates`

Imports email templates from blade files or a JSON manifest.

```bash
php artisan autoresponder:import-templates
php artisan autoresponder:import-templates --path=resources/templates/emails.json
php artisan autoresponder:import-templates --overwrite  # replace existing templates
```

## A/B Testing

Each step can have multiple variants. The system splits traffic and tracks performance:

```php
$step = $sequence->steps()->create([
    'order'       => 1,
    'delay_value' => 0,
    'delay_unit'  => 'minutes',
    'subject'     => 'Welcome!',
    'body'        => '<p>Welcome variant A</p>',
]);

$step->variants()->create([
    'variant_label'  => 'B',
    'subject'        => 'Hey there!',
    'body'           => '<p>Welcome variant B</p>',
    'traffic_weight' => 50, // percentage
]);
```

After enough sends, query analytics to pick a winner:

```php
use ColorrageAR\Autoresponder\Services\AnalyticsService;

$analytics = app(AnalyticsService::class);
$report = $analytics->stepVariantReport($step);
// Returns open rates, click rates, and unsubscribe rates per variant
```

## Broadcast Campaigns

Send one-off or scheduled emails to a mailer list:

```php
use ColorrageAR\Autoresponder\Services\CampaignService;

$campaign = app(CampaignService::class);

$campaign->create([
    'name'         => 'April Newsletter',
    'list_id'      => $list->id,
    'subject'      => 'News for {{first_name}}',
    'body'         => '<p>Here is what happened this month...</p>',
    'scheduled_at' => now()->addHours(2),
]);
```

## Mailer Lists

```php
use ColorrageAR\Autoresponder\Services\ListService;

$listService = app(ListService::class);

// Manual list — add subscribers explicitly
$manual = $listService->create(['name' => 'VIP Customers', 'type' => 'manual']);
$listService->addSubscriber($manual, $user);

// Dynamic list — subscribers resolved by a query at send time
$dynamic = $listService->create([
    'name'  => 'Active Premium Users',
    'type'  => 'dynamic',
    'query' => [
        'subscription_status' => 'active',
        'last_login_at'       => ['>', now()->subDays(30)],
    ],
]);
```

## Testing

The package uses [Orchestra Testbench](https://github.com/orchestral/testbench) for testing within a Laravel environment.

```bash
composer test
```

Your `phpunit.xml` or `composer.json` should have:

```json
{
    "scripts": {
        "test": "vendor/bin/phpunit"
    }
}
```

To run tests with coverage:

```bash
composer test -- --coverage
```

### Test utilities

The package ships a `AutoresponderTestHelpers` trait you can use in your application tests:

```php
use ColorrageAR\Autoresponder\Testing\AutoresponderTestHelpers;

class MyFeatureTest extends TestCase
{
    use AutoresponderTestHelpers;

    public function test_welcome_sequence_sends_first_email()
    {
        $sequence = $this->createTestSequence('Welcome', steps: 3);
        $user = User::factory()->create();

        Autoresponder::enroll($user, $sequence);

        $this->processEnrollments();

        $this->assertStepSentTo($user, $sequence->steps->first());
    }
}
```

## License

MIT License. See [LICENSE](LICENSE) for details.
