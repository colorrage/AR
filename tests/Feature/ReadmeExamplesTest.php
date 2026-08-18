<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use App\Models\User;
use ColorrageAR\Autoresponder\Events\SubscriberRegistered;
use ColorrageAR\Autoresponder\Facades\Autoresponder;
use ColorrageAR\Autoresponder\Models\Enrollment;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Sequence;
use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Services\AutoresponderService;
use ColorrageAR\Autoresponder\Tests\TestCase;
use ColorrageAR\Autoresponder\Jobs\SendStepEmail;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

/**
 * Executes the code snippets published in README.md so documentation drift fails the build.
 *
 * Added by T6, whose whole purpose was repairing a README that documented an API whose
 * public surface had never existed. Grep-checking identifiers catches a wrong class name;
 * only running the snippets catches a wrong *shape* — which is how the old README came to
 * show steps carrying inline 'subject' and 'body' columns that the schema does not have.
 *
 * If you change a Quick Start or Testing snippet in README.md, change it here too.
 */
class ReadmeExamplesTest extends TestCase
{
    private function buildFromReadme(): array
    {
        // README: 1. Create a template
        $welcome = Template::create([
            'name' => 'Welcome email',
            'subject' => 'Welcome to ##config.app.name##!',
            'body' => '<p>Hi ##subscriber.name##, thanks for signing up.</p>',
            'locale' => 'en',
        ]);

        $guide = Template::create([
            'name' => 'Guide', 'subject' => 'Guide', 'body' => 'body', 'locale' => 'en',
        ]);

        // README: 2. Create a sequence
        $sequence = Sequence::create([
            'name' => 'Welcome Series',
            'slug' => 'welcome-series',
            'trigger_type' => 'user_registration',
            'status' => 'active',
            'is_active' => true,
        ]);

        // README: 3. Add steps
        $sequence->steps()->create([
            'step_number' => 1,
            'name' => 'Welcome',
            'template_id' => $welcome->id,
            'delay_type' => 'from_trigger',
            'delay_value' => 0,
            'delay_unit' => 'minutes',
        ]);

        $sequence->steps()->create([
            'step_number' => 2,
            'name' => 'Getting started',
            'template_id' => $guide->id,
            'subject_override' => 'Getting started guide',
            'delay_type' => 'from_prev_step',
            'delay_value' => 24,
            'delay_unit' => 'hours',
        ]);

        return [$sequence, $welcome];
    }

    public function test_readme_quick_start_snippets_run(): void
    {
        [$sequence] = $this->buildFromReadme();

        $this->assertSame(2, $sequence->steps()->count());
        $this->assertDatabaseHas('ar_sequences', ['slug' => 'welcome-series']);
        $this->assertDatabaseHas('ar_steps', ['step_number' => 2, 'subject_override' => 'Getting started guide']);
    }

    public function test_readme_facade_enroll_by_trigger_type(): void
    {
        $this->buildFromReadme();
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        // README: Autoresponder::enroll('user_registration', $user);
        $enrollments = Autoresponder::enroll('user_registration', $user);

        $this->assertCount(1, $enrollments);
        $this->assertDatabaseHas('ar_enrollments', ['email' => 'ada@example.com']);
    }

    public function test_readme_enroll_in_specific_sequence(): void
    {
        [$sequence] = $this->buildFromReadme();
        $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

        // README: app(AutoresponderService::class)->enrollInSequence($sequence, $user);
        $enrollment = app(AutoresponderService::class)->enrollInSequence($sequence, $user);

        $this->assertNotNull($enrollment);
        $this->assertDatabaseHas('ar_enrollments', ['email' => 'bob@example.com']);
    }

    public function test_readme_service_enroll_assertion_snippet(): void
    {
        $this->buildFromReadme();
        $user = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'x']);

        // README testing section, synchronous variant
        app(AutoresponderService::class)->enroll('user_registration', $user);

        $this->assertDatabaseHas('ar_enrollments', ['email' => $user->email]);
    }

    public function test_readme_queued_listener_assertion_snippet(): void
    {
        $this->buildFromReadme();
        $user = User::create(['name' => 'Dee', 'email' => 'dee@example.com', 'password' => 'x']);

        // README testing section, queued variant
        Queue::fake();

        event(new SubscriberRegistered($user));

        Queue::assertPushed(CallQueuedListener::class);
    }

    /**
     * The README's Quick Start creates templates with `body` and no `body_html`, while
     * SendStepEmail renders from `body_html`. That looks like a break, and T6's review
     * initially reported it as one — but Template::bodyHtml() is an accessor that falls
     * back to `body`, so the documented shape renders correctly.
     *
     * This test pins that end to end: if anyone removes the accessor fallback, or points
     * the job at the raw column, the README's Quick Start silently starts sending empty
     * emails and this fails.
     */
    public function test_readme_template_shape_actually_sends(): void
    {
        [$sequence] = $this->buildFromReadme();
        $user = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'x']);

        $enrollment = app(AutoresponderService::class)->enrollInSequence($sequence, $user);
        $step = $sequence->steps()->where('step_number', 1)->first();

        // The documented shape really does leave the body_html *column* empty...
        $this->assertNull($step->template->getRawOriginal('body_html'));
        // ...and the accessor is what makes it renderable.
        $this->assertNotNull($step->template->body_html);

        (new SendStepEmail($enrollment->id, $step->id))->handle(
            app(AutoresponderService::class),
            app(\ColorrageAR\Autoresponder\Services\TokenService::class),
        );

        $log = SendLog::where('email', 'eve@example.com')->first();

        $this->assertNotNull($log, 'a body-only template must still produce a send log');
        $this->assertNotEmpty($log->body_html, 'rendered body must not be empty');
        $this->assertStringContainsString('thanks for signing up', $log->body_html);
        $this->assertStringContainsString('Eve', $log->body_html, 'subscriber token must be replaced');
    }

    /**
     * Pins the locale-matching behaviour the README's Languages section documents:
     * enroll() filters sequences by an EXACT default_locale match with no fallback, so a
     * subscriber whose locale has no sequence is silently enrolled in nothing.
     *
     * This is documented rather than fixed (changing it would alter behaviour), so the test
     * exists to make sure the documentation stays true — not to endorse the behaviour.
     */
    public function test_readme_locale_matching_is_exact_with_no_fallback(): void
    {
        [$sequence] = $this->buildFromReadme();

        // The README's Sequence::create() omits default_locale; the column defaults to 'en'
        // at the database level, which is what enroll() then matches against.
        $this->assertSame('en', $sequence->fresh()->default_locale);

        $english = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->assertCount(1, Autoresponder::enroll('user_registration', $english, ['locale' => 'en']));

        // Same trigger, a locale with no sequence: no enrollment, no exception.
        $german = User::create(['name' => 'Gus', 'email' => 'gus@example.com', 'password' => 'x']);
        $result = Autoresponder::enroll('user_registration', $german, ['locale' => 'de']);

        $this->assertCount(0, $result, 'unmatched locale must yield no enrollment');
        $this->assertDatabaseMissing('ar_enrollments', ['email' => 'gus@example.com']);
    }
}
