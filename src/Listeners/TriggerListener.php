<?php

namespace CmrManagement\Autoresponder\Listeners;

use CmrManagement\Autoresponder\Events\CustomTrigger;
use CmrManagement\Autoresponder\Events\ListSubscribed;
use CmrManagement\Autoresponder\Events\PaymentFailed;
use CmrManagement\Autoresponder\Events\PaymentSucceeded;
use CmrManagement\Autoresponder\Events\SubscriberLoggedIn;
use CmrManagement\Autoresponder\Events\SubscriberRegistered;
use CmrManagement\Autoresponder\Services\AutoresponderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use function CmrManagement\Autoresponder\ar_log;

class TriggerListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue;

    public function __construct(protected AutoresponderService $service)
    {
        $this->queue = config('autoresponder.queue', 'emails');
    }

    public function handleSubscriberRegistered(SubscriberRegistered $event): void
    {
        $this->triggerSequences('user_registration', $event->subscriber);
    }

    public function handleSubscriberLoggedIn(SubscriberLoggedIn $event): void
    {
        $this->triggerSequences('first_login', $event->subscriber);
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->triggerSequences('payment_success', $event->subscriber, $event->paymentData);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $this->triggerSequences('payment_failed', $event->subscriber, $event->paymentData);
    }

    public function handleListSubscribed(ListSubscribed $event): void
    {
        $this->triggerSequences('mailing_list', $event->subscriber, [
            'email' => $event->email,
            'list_id' => $event->listId,
        ]);
    }

    public function handleCustomTrigger(CustomTrigger $event): void
    {
        $this->triggerSequences($event->triggerType, $event->subscriber, $event->data);
    }

    protected function triggerSequences(string $triggerType, $subscriber, array $data = []): void
    {
        try {
            $this->service->enroll($triggerType, $subscriber, $data);
        } catch (\Exception $e) {
            ar_log()->error('Failed to trigger autoresponder', [
                'trigger_type' => $triggerType,
                'subscriber_id' => method_exists($subscriber, 'getSubscribableId')
                    ? $subscriber->getSubscribableId()
                    : null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function subscribe($events): array
    {
        return [
            SubscriberRegistered::class => 'handleSubscriberRegistered',
            SubscriberLoggedIn::class => 'handleSubscriberLoggedIn',
            PaymentSucceeded::class => 'handlePaymentSucceeded',
            PaymentFailed::class => 'handlePaymentFailed',
            ListSubscribed::class => 'handleListSubscribed',
            CustomTrigger::class => 'handleCustomTrigger',
        ];
    }
}
