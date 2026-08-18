<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use App\Models\User;
use ColorrageAR\Autoresponder\Contracts\Subscribable;
use ColorrageAR\Autoresponder\Jobs\SendSingleCampaignEmail;
use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Services\ListService;
use ColorrageAR\Autoresponder\Services\TokenService;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The branch where a recipient is backed by a real host model.
 *
 * Untestable before T3.1 added a users table, which is why
 * tests/Support/App/Models/User.php implements Subscribable yet was referenced by
 * nothing. This is also the shape a host application actually uses in production —
 * the no-host-model shape every other campaign test uses is the imported-address one.
 */
class CampaignHostModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'irrelevant',
        ], $attributes));
    }

    private function send(int $sendLogId): void
    {
        (new SendSingleCampaignEmail($sendLogId))->handle(app(TokenService::class));
    }

    public function test_a_real_foreign_key_is_stored_for_a_host_backed_recipient(): void
    {
        $user = $this->user();
        $campaign = $this->makeListCampaign($this->makeStaticListForSubscribers([$user]));

        app(CampaignService::class)->sendCampaign($campaign);

        $this->assertSame(
            $user->getKey(),
            SendLog::where('campaign_id', $campaign->id)->value('subscriber_id'),
        );
    }

    public function test_a_recipient_without_a_host_model_stores_null_not_zero(): void
    {
        // The counterpart assertion: anonymous wrappers return 0 from
        // getSubscribableId(), which is not a real key and must not become FK 0.
        $campaign = $this->makeListCampaign($this->makeStaticList(1));

        app(CampaignService::class)->sendCampaign($campaign);

        $this->assertNull(SendLog::where('campaign_id', $campaign->id)->value('subscriber_id'));
    }

    public function test_the_list_service_returns_the_model_itself_when_it_is_subscribable(): void
    {
        $user = $this->user();
        $list = $this->makeStaticListForSubscribers([$user]);

        $resolved = app(ListService::class)->getSubscribers($list);

        $this->assertCount(1, $resolved);
        $this->assertInstanceOf(User::class, $resolved->first(), 'not wrapped');
        $this->assertSame('Ada Lovelace', $resolved->first()->getSubscribableName());
    }

    public function test_the_real_name_reaches_the_rendered_email(): void
    {
        $user = $this->user();
        $campaign = $this->makeListCampaign(
            $this->makeStaticListForSubscribers([$user]),
            ['subject' => 'Hello ##subscriber.name##'],
        );
        app(CampaignService::class)->sendCampaign($campaign);

        $this->send(SendLog::where('campaign_id', $campaign->id)->value('id'));

        $row = SendLog::where('campaign_id', $campaign->id)->first();
        $this->assertStringContainsString('Ada Lovelace', (string) $row->subject);
        $this->assertStringContainsString('Ada Lovelace', (string) $row->body_html);
    }

    public function test_without_a_host_model_the_name_falls_back_to_a_placeholder(): void
    {
        // Same campaign shape, no host model. The two branches must be visibly
        // different: T2's own verification produced "Welcome Subscriber" here.
        $campaign = $this->makeListCampaign(
            $this->makeStaticList(1),
            ['subject' => 'Hello ##subscriber.name##'],
        );
        app(CampaignService::class)->sendCampaign($campaign);

        $this->send(SendLog::where('campaign_id', $campaign->id)->value('id'));

        $row = SendLog::where('campaign_id', $campaign->id)->first();
        $this->assertStringContainsString('Subscriber', (string) $row->subject);
        $this->assertStringNotContainsString('Ada Lovelace', (string) $row->subject);
    }

    public function test_the_locale_column_is_honoured_when_mapped(): void
    {
        config(['autoresponder.subscriber_columns.locale' => 'locale']);
        $user = $this->user(['locale' => 'ro']);

        $resolved = app(ListService::class)
            ->getSubscribers($this->makeStaticListForSubscribers([$user]));

        $this->assertSame('ro', $resolved->first()->getSubscribableLocale());
    }

    public function test_a_dangling_subscriber_id_degrades_instead_of_throwing(): void
    {
        $user = $this->user();
        $list = $this->makeStaticListForSubscribers([$user]);
        $campaign = $this->makeListCampaign($list, ['subject' => 'Hello ##subscriber.name##']);
        app(CampaignService::class)->sendCampaign($campaign);

        // The user disappears after preparation — a deletion between preparing and
        // sending must not take the send down with it.
        $user->delete();

        $this->send(SendLog::where('campaign_id', $campaign->id)->value('id'));

        $row = SendLog::where('campaign_id', $campaign->id)->first();
        $this->assertSame('sent', $row->status);
        $this->assertStringContainsString('Subscriber', (string) $row->subject, 'fell back to the wrapper');
    }

    public function test_a_host_model_that_does_not_implement_the_contract_is_wrapped(): void
    {
        // env-app's own User does not implement Subscribable — a live misconfiguration
        // recorded in T1's research. Resolution must degrade rather than fatal.
        config(['autoresponder.subscriber_model' => PlainSubscriber::class]);
        $user = $this->user(['email' => 'plain@example.com']);

        $list = $this->makeStaticList(0);
        ListSubscriber::create([
            'list_id' => $list->id,
            'subscriber_id' => $user->getKey(),
            'email' => 'plain@example.com',
            'name' => 'Plain Row Name',
            'status' => 'active',
            'subscribed_at' => now(),
        ]);

        $resolved = app(ListService::class)->getSubscribers($list);

        $this->assertCount(1, $resolved);
        $this->assertNotInstanceOf(PlainSubscriber::class, $resolved->first());
        $this->assertInstanceOf(Subscribable::class, $resolved->first());
        $this->assertSame('Plain Row Name', $resolved->first()->getSubscribableName());
    }
}

/**
 * A subscriber model that does not implement Subscribable, mirroring the
 * misconfiguration present in the one host application that exists today.
 */
class PlainSubscriber extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';

    protected $guarded = [];
}
