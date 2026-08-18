<?php

namespace ColorrageAR\Autoresponder\Tests\Support;

use ColorrageAR\Autoresponder\Contracts\Subscribable;
use ColorrageAR\Autoresponder\Models\Campaign;
use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\Template;

/**
 * Shared campaign fixtures.
 *
 * Signatures are pinned by T3's execution plan so the parallel coverage slices can
 * build against them without reading each other's code. Change them only through
 * that plan.
 */
trait CampaignFixtures
{
    /**
     * A template whose body carries exactly one anchor, so link-rewriting is assertable.
     */
    protected function makeTemplate(array $attributes = []): Template
    {
        return Template::create(array_merge([
            'name' => 'Test Template',
            'subject' => 'Hello ##subscriber.name##',
            'body' => 'Hello ##subscriber.name##',
            'body_html' => '<p>Hello ##subscriber.name##, see <a href="https://example.com/go">this</a>.</p>',
            'locale' => 'en',
        ], $attributes));
    }

    /**
     * A static mailer list whose rows have NO subscriber_id.
     *
     * This is the shape an imported address takes, and the shape that was fatal
     * before T2 — the anonymous-wrapper path through ListService.
     */
    protected function makeStaticList(int $recipients = 3, string $emailPattern = 'u%d@example.com'): MailerList
    {
        $list = MailerList::create([
            'name' => 'Imported List',
            'type' => 'manual',
            'status' => 'active',
        ]);

        for ($i = 0; $i < $recipients; $i++) {
            ListSubscriber::create([
                'list_id' => $list->id,
                'email' => sprintf($emailPattern, $i),
                'name' => "Recipient {$i}",
                'status' => 'active',
                'subscribed_at' => now(),
            ]);
        }

        return $list;
    }

    /**
     * A static mailer list whose rows each link to a real host-model subscriber.
     *
     * The counterpart to makeStaticList(): here getSubscribableId() returns a usable
     * key, so a real foreign key is stored and token substitution resolves a real
     * name instead of the 'Subscriber' fallback. Requires the users table.
     *
     * @param  iterable<object>  $subscribers  Models exposing id, email and name.
     */
    protected function makeStaticListForSubscribers(iterable $subscribers): MailerList
    {
        $list = MailerList::create([
            'name' => 'Host Model List',
            'type' => 'manual',
            'status' => 'active',
        ]);

        foreach ($subscribers as $subscriber) {
            $email = $subscriber instanceof Subscribable
                ? $subscriber->getSubscribableEmail()
                : $subscriber->email;

            $name = $subscriber instanceof Subscribable
                ? $subscriber->getSubscribableName()
                : ($subscriber->name ?? null);

            ListSubscriber::create([
                'list_id' => $list->id,
                'subscriber_id' => $subscriber->getKey(),
                'email' => $email,
                'name' => $name,
                'status' => 'active',
                'subscribed_at' => now(),
            ]);
        }

        return $list;
    }

    /**
     * A draft campaign targeting mailer lists.
     *
     * `filter_type` goes on the column and `filter_params` carries only the list ids —
     * exactly what the Filament stub writes, and the mismatch that made every
     * campaign resolve zero recipients before T2.
     */
    protected function makeListCampaign(MailerList $list, array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'name' => 'Test Campaign',
            'subject' => 'Test Subject',
            'template_id' => $this->makeTemplate()->id,
            'filter_type' => 'mailer_lists',
            'filter_params' => ['selected_lists' => [$list->id]],
            'status' => 'draft',
        ], $attributes));
    }

    /**
     * A draft campaign targeting a comma-separated address string.
     */
    protected function makeManualCampaign(string $emails, array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'name' => 'Manual Campaign',
            'subject' => 'Test Subject',
            'template_id' => $this->makeTemplate()->id,
            'filter_type' => 'manual_emails',
            'filter_params' => ['manual_emails' => $emails],
            'status' => 'draft',
        ], $attributes));
    }
}
