<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Services;

use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Services\ListImportService;
use ColorrageAR\Autoresponder\Support\RejectedRow;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ListImportServiceTest extends TestCase
{
    private ListImportService $service;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ListImportService();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    private function fixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ar_import_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function list(string $type = 'manual'): MailerList
    {
        return MailerList::create([
            'name' => 'Import Target',
            'type' => $type,
            'status' => 'active',
        ]);
    }

    private function existingRow(MailerList $list, string $email, array $attributes = []): ListSubscriber
    {
        return ListSubscriber::create(array_merge([
            'list_id' => $list->id,
            'email' => $email,
            'name' => 'Original Name',
            'status' => 'active',
            'subscribed_at' => now()->subMonth(),
        ], $attributes));
    }

    // ── The opt-out guarantee ────────────────────────────────────────

    public function test_an_address_unsubscribed_from_this_list_is_never_reactivated(): void
    {
        // The single most important test in T5: an import must not undo an explicit
        // per-list opt-out, and ListService::addSubscriber() — which this service
        // deliberately does not call — would have flipped it back to active.
        $list = $this->list();
        $optedOutAt = now()->subWeek();

        $this->existingRow($list, 'gone@example.com', [
            'status' => 'unsubscribed',
            'unsubscribed_at' => $optedOutAt,
            'name' => 'Left Us',
        ]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\ngone@example.com,Came Back\nnew@example.com,New\n"),
            $list,
        );

        $row = ListSubscriber::where('list_id', $list->id)->where('email', 'gone@example.com')->firstOrFail();

        $this->assertSame('unsubscribed', $row->status, 'the opt-out must survive');
        $this->assertNotNull($row->unsubscribed_at);
        $this->assertSame(
            $optedOutAt->format('Y-m-d H:i:s'),
            $row->unsubscribed_at->format('Y-m-d H:i:s'),
            'the original opt-out timestamp must be intact',
        );
        $this->assertSame('Left Us', $row->name, 'a suppressed row is left entirely alone');

        $this->assertSame(1, $report->suppressed());
        $this->assertSame(1, $report->accepted());
        $this->assertTrue($report->reconciles());

        $reasons = array_map(static fn (RejectedRow $r): string => $r->reason, $report->rejected());
        $this->assertContains(RejectedRow::REASON_UNSUBSCRIBED_FROM_LIST, $reasons);
    }

    public function test_a_globally_suppressed_address_creates_no_active_row(): void
    {
        $list = $this->list();

        Unsubscribe::create([
            'email' => 'blocked@example.com',
            'unsubscribed_at' => now()->subYear(),
        ]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\nblocked@example.com,Blocked\nok@example.com,Fine\n"),
            $list,
        );

        $this->assertSame(
            0,
            ListSubscriber::where('list_id', $list->id)->where('email', 'blocked@example.com')->count(),
            'a globally suppressed address must not be added at all',
        );
        $this->assertSame(1, $report->suppressed());
        $this->assertSame(1, $report->accepted());
        $this->assertTrue($report->reconciles());

        $reasons = array_map(static fn (RejectedRow $r): string => $r->reason, $report->rejected());
        $this->assertContains(RejectedRow::REASON_SUPPRESSED_GLOBALLY, $reasons);
    }

    public function test_global_suppression_is_matched_case_insensitively(): void
    {
        $list = $this->list();

        Unsubscribe::create(['email' => 'blocked@example.com', 'unsubscribed_at' => now()]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\n  BLOCKED@Example.COM ,Shouty\n"),
            $list,
        );

        $this->assertSame(1, $report->suppressed());
        $this->assertSame(0, $report->accepted());
        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count());
    }

    public function test_the_service_upsert_preserves_an_opt_out_that_appears_after_classification(): void
    {
        // A real race, forced. The service classifies against a SELECT and then writes; if
        // an opt-out lands in between, only the upsert's update-column list protects it.
        //
        // This is the test that actually guards the service's column list. Asserting the
        // upsert in isolation (below) proves the SQL grammar behaves, but would keep passing
        // if someone added `status` to the service's own list — because classification
        // normally filters unsubscribed rows out before the write ever sees them.
        $list = $this->list();
        $optedOutAt = now()->subWeek();
        $injected = false;

        DB::listen(function ($query) use ($list, $optedOutAt, &$injected): void {
            // Fire once, right after the classification read of the list's rows.
            if ($injected || ! str_contains($query->sql, 'list_subscribers')) {
                return;
            }

            if (! str_starts_with(strtolower(trim($query->sql)), 'select')) {
                return;
            }

            $injected = true;

            ListSubscriber::insert([
                'list_id' => $list->id,
                'email' => 'raced@example.com',
                'name' => 'Opted Out Mid-Import',
                'status' => 'unsubscribed',
                'subscribed_at' => $optedOutAt,
                'unsubscribed_at' => $optedOutAt,
                'created_at' => $optedOutAt,
                'updated_at' => $optedOutAt,
            ]);
        });

        $report = $this->service->importCsv(
            $this->fixture("email,name\nraced@example.com,Should Not Reactivate\n"),
            $list,
        );

        $this->assertTrue($injected, 'the race was actually simulated');

        $row = ListSubscriber::where('list_id', $list->id)->where('email', 'raced@example.com')->firstOrFail();

        $this->assertSame('unsubscribed', $row->status, 'the opt-out must survive the write');
        $this->assertSame($optedOutAt->format('Y-m-d H:i:s'), $row->unsubscribed_at->format('Y-m-d H:i:s'));

        // The row was invisible when classified, so it was counted as a new accept. The
        // count being optimistic is fine; the database state being wrong would not be.
        $this->assertSame(1, $report->accepted());
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count(), 'no duplicate row');
    }

    public function test_the_upsert_update_columns_preserve_an_opt_out_under_a_race(): void
    {
        // The narrower check: that the SQL grammar itself honours a partial update-column
        // list on this driver — the thing the technical plan flagged as unproven. Kept
        // alongside the forced-race test above, which is what guards the service's list.
        $list = $this->list();
        $optedOutAt = now()->subWeek();

        $this->existingRow($list, 'raced@example.com', [
            'status' => 'unsubscribed',
            'unsubscribed_at' => $optedOutAt,
        ]);

        ListSubscriber::query()->upsert(
            [[
                'list_id' => $list->id,
                'email' => 'raced@example.com',
                'name' => 'Refreshed Name',
                'locale' => 'en',
                'status' => 'active',
                'subscribed_at' => now(),
                'import_source' => 'race.csv',
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['list_id', 'email'],
            ['name', 'locale', 'import_source', 'imported_at', 'updated_at'],
        );

        $row = ListSubscriber::where('list_id', $list->id)->where('email', 'raced@example.com')->firstOrFail();

        $this->assertSame('unsubscribed', $row->status, 'status is not in the update column list');
        $this->assertSame($optedOutAt->format('Y-m-d H:i:s'), $row->unsubscribed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Refreshed Name', $row->name, 'but the named columns do update');
        $this->assertSame('race.csv', $row->import_source);
    }

    // ── Unnormalized stored data (verify findings 1 and 2) ───────────

    public function test_a_globally_suppressed_address_is_matched_whatever_casing_it_was_stored_in(): void
    {
        // UnsubscribeController::process() stores whatever SendLog.to_email held, so
        // ar_unsubscribes really does contain mixed-case addresses. A plain whereIn against
        // normalized values misses them on a case-sensitive collation and imports a
        // suppressed address — which is why this compares with LOWER(email), as
        // CampaignService already does for the same table.
        $list = $this->list();

        Unsubscribe::create(['email' => 'Blocked@Example.COM', 'unsubscribed_at' => now()]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\nblocked@example.com,Sneaking Back\n"),
            $list,
        );

        $this->assertSame(1, $report->suppressed());
        $this->assertSame(0, $report->accepted());
        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count());
    }

    public function test_an_existing_opt_out_is_matched_whatever_casing_it_was_stored_in(): void
    {
        // ListService::addSubscriber() and syncFromModel() do not normalize either.
        $list = $this->list();
        $optedOutAt = now()->subWeek();

        $this->existingRow($list, 'Known@Example.COM', [
            'status' => 'unsubscribed',
            'unsubscribed_at' => $optedOutAt,
        ]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\nknown@example.com,Sneaking Back\n"),
            $list,
        );

        $this->assertSame(1, $report->suppressed());
        $this->assertSame(0, $report->accepted());
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count(), 'no second row');

        $row = ListSubscriber::where('list_id', $list->id)->firstOrFail();
        $this->assertSame('unsubscribed', $row->status);
        $this->assertSame($optedOutAt->format('Y-m-d H:i:s'), $row->unsubscribed_at->format('Y-m-d H:i:s'));
    }

    public function test_an_existing_active_row_stored_with_other_casing_is_refreshed_not_duplicated(): void
    {
        // The unique index compares raw values, so inserting the normalized spelling would
        // create a second row for the same person. The refresh has to target the row that
        // actually exists.
        $list = $this->list();
        $this->existingRow($list, 'Known@Example.COM', ['name' => 'Old Name']);

        $report = $this->service->importCsv(
            $this->fixture("email,name,locale\nknown@example.com,New Name,ro\n"),
            $list,
        );

        $this->assertSame(0, $report->accepted());
        $this->assertSame(1, $report->duplicate());
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count(), 'still one row');

        $row = ListSubscriber::where('list_id', $list->id)->firstOrFail();
        $this->assertSame('Known@Example.COM', $row->email, 'the stored spelling is left as it was');
        $this->assertSame('New Name', $row->name, 'but the row is refreshed');
        $this->assertSame('ro', $row->locale);
        $this->assertNotNull($row->import_source);
        $this->assertSame('active', $row->status);
    }

    public function test_when_dirty_data_holds_both_casings_the_opt_out_wins(): void
    {
        // A case-sensitive collation can already hold both spellings. If either is
        // unsubscribed, that is the one that counts.
        $list = $this->list();
        $this->existingRow($list, 'both@example.com', ['status' => 'active']);
        $this->existingRow($list, 'BOTH@example.com', [
            'status' => 'unsubscribed',
            'unsubscribed_at' => now()->subDay(),
        ]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\nboth@example.com,Ambiguous\n"),
            $list,
        );

        $this->assertSame(1, $report->suppressed());
        $this->assertSame(0, $report->accepted());
        $this->assertSame(2, ListSubscriber::where('list_id', $list->id)->count(), 'neither row was added to');
    }

    // ── Column limits (verify finding 3) ─────────────────────────────

    public function test_an_over_long_name_is_truncated_rather_than_failing_the_import(): void
    {
        // The name column is 255 characters. On MySQL an over-long value throws mid-file,
        // with earlier chunks already committed and no report returned — so it is capped here
        // instead. SQLite would have stored all 300 characters happily.
        $list = $this->list();
        $longName = str_repeat('x', 300);

        $report = $this->service->importCsv(
            $this->fixture("email,name\na@example.com,{$longName}\nb@example.com,Short\n"),
            $list,
        );

        $this->assertSame(2, $report->accepted());
        $this->assertTrue($report->reconciles());

        $stored = ListSubscriber::where('list_id', $list->id)->where('email', 'a@example.com')->value('name');

        $this->assertSame(255, mb_strlen((string) $stored));
        $this->assertSame(str_repeat('x', 255), $stored);
    }

    public function test_a_name_at_the_limit_is_stored_intact(): void
    {
        $list = $this->list();
        $exact = str_repeat('y', 255);

        $this->service->importCsv(
            $this->fixture("email,name\na@example.com,{$exact}\n"),
            $list,
        );

        $this->assertSame($exact, ListSubscriber::where('list_id', $list->id)->value('name'));
    }

    // ── Reconciliation ───────────────────────────────────────────────

    public function test_every_row_lands_in_exactly_one_bucket(): void
    {
        $list = $this->list();

        $this->existingRow($list, 'already@example.com');
        $this->existingRow($list, 'optedout@example.com', [
            'status' => 'unsubscribed',
            'unsubscribed_at' => now()->subDay(),
        ]);
        Unsubscribe::create(['email' => 'global@example.com', 'unsubscribed_at' => now()]);

        // 10 data rows: 3 accepted, 2 duplicate, 2 suppressed, 3 invalid.
        $csv = <<<CSV
        email,name,locale
        new1@example.com,New One,en
        new2@example.com,New Two,ro
        new3@example.com,New Three,
        new1@example.com,Repeat Of One,en
        already@example.com,Already Here,en
        optedout@example.com,Opted Out,en
        global@example.com,Globally Gone,en
        not-an-email,Bad Address,en
        ,Blank Address,en
        short@example.com,Missing Column
        CSV;

        $report = $this->service->importCsv($this->fixture($csv . "\n"), $list);

        $this->assertSame(10, $report->rowsRead());
        $this->assertSame(3, $report->accepted());
        $this->assertSame(2, $report->duplicate());
        $this->assertSame(2, $report->suppressed());
        $this->assertSame(3, $report->invalid());
        $this->assertTrue($report->reconciles(), 'the acceptance proof');
    }

    public function test_invalid_rows_are_each_reported_with_a_reason_and_a_line(): void
    {
        $list = $this->list();

        $csv = "email,name\nnot-an-email,Bad\n,Blank\nok@example.com,Fine\n";

        $report = $this->service->importCsv($this->fixture($csv), $list);

        $this->assertSame(2, $report->invalid());
        $this->assertCount(2, $report->rejected());

        $byReason = [];
        foreach ($report->rejected() as $rejected) {
            $byReason[$rejected->reason] = $rejected;
        }

        $this->assertArrayHasKey(RejectedRow::REASON_INVALID_EMAIL, $byReason);
        $this->assertArrayHasKey(RejectedRow::REASON_MISSING_EMAIL, $byReason);
        $this->assertSame(2, $byReason[RejectedRow::REASON_INVALID_EMAIL]->line);
        $this->assertSame(3, $byReason[RejectedRow::REASON_MISSING_EMAIL]->line);
    }

    public function test_a_malformed_row_is_invalid_and_the_rest_still_import(): void
    {
        $list = $this->list();

        $csv = "email,name,locale\ngood1@example.com,One,en\nshort@example.com,Two\ngood2@example.com,Three,ro\n";

        $report = $this->service->importCsv($this->fixture($csv), $list);

        $this->assertSame(2, $report->accepted());
        $this->assertSame(1, $report->invalid());
        $this->assertSame(RejectedRow::REASON_MALFORMED, $report->rejected()[0]->reason);
        $this->assertTrue($report->reconciles());
        $this->assertSame(2, ListSubscriber::where('list_id', $list->id)->count());
    }

    // ── Duplicates ───────────────────────────────────────────────────

    public function test_a_repeated_address_yields_one_subscriber(): void
    {
        $list = $this->list();

        $csv = "email,name\ndup@example.com,First\ndup@example.com,Second\nDUP@EXAMPLE.COM,Third\n";

        $report = $this->service->importCsv($this->fixture($csv), $list);

        $this->assertSame(1, $report->accepted());
        $this->assertSame(2, $report->duplicate());
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count());
        $this->assertSame('First', ListSubscriber::where('list_id', $list->id)->value('name'));
    }

    public function test_a_repeat_that_spans_a_chunk_boundary_is_still_a_duplicate(): void
    {
        // The property that lets the importer skip an in-memory seen-set entirely: with a
        // chunk size of 2, the repeat is in a later chunk than the original, so only the
        // database can catch it.
        config()->set('autoresponder.import.chunk_size', 2);

        $list = $this->list();

        $csv = "email,name\na@example.com,A\nb@example.com,B\nc@example.com,C\na@example.com,A Again\n";

        $report = $this->service->importCsv($this->fixture($csv), $list);

        $this->assertSame(3, $report->accepted());
        $this->assertSame(1, $report->duplicate());
        $this->assertSame(4, $report->rowsRead());
        $this->assertTrue($report->reconciles());
        $this->assertSame(3, ListSubscriber::where('list_id', $list->id)->count());
    }

    public function test_an_address_already_active_is_refreshed_not_duplicated(): void
    {
        $list = $this->list();
        $row = $this->existingRow($list, 'known@example.com', ['name' => 'Old Name', 'locale' => null]);
        $originalSubscribedAt = $row->subscribed_at;

        $report = $this->service->importCsv(
            $this->fixture("email,name,locale\nknown@example.com,New Name,ro\n"),
            $list,
        );

        $this->assertSame(0, $report->accepted());
        $this->assertSame(1, $report->duplicate());
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count(), 'no second row');

        $row->refresh();
        $this->assertSame('New Name', $row->name, 'name is refreshed');
        $this->assertSame('ro', $row->locale, 'locale is refreshed');
        $this->assertSame('active', $row->status);
        $this->assertSame(
            $originalSubscribedAt->format('Y-m-d H:i:s'),
            $row->subscribed_at->format('Y-m-d H:i:s'),
            'subscribed_at is not in the update column list, so the original date survives',
        );
        $this->assertNotNull($row->imported_at, 'provenance is refreshed on the duplicate branch too');
        $this->assertNotNull($row->import_source);
    }

    // ── Pruning ──────────────────────────────────────────────────────

    public function test_an_import_never_prunes_addresses_absent_from_the_file(): void
    {
        // syncFromModel() prunes; the file path must not. Conflating them would silently
        // unsubscribe addresses the operator never mentioned.
        $list = $this->list();
        $untouched = $this->existingRow($list, 'keepme@example.com');

        $report = $this->service->importCsv(
            $this->fixture("email,name\nsomeoneelse@example.com,Other\n"),
            $list,
        );

        $untouched->refresh();

        $this->assertSame('active', $untouched->status);
        $this->assertNull($untouched->unsubscribed_at);
        $this->assertSame(1, $report->accepted());
        $this->assertSame(2, ListSubscriber::where('list_id', $list->id)->count());
    }

    // ── Provenance ───────────────────────────────────────────────────

    public function test_every_written_row_records_where_it_came_from(): void
    {
        $list = $this->list();
        $path = $this->fixture("email,name\na@example.com,A\nb@example.com,B\n");

        $this->service->importCsv($path, $list);

        $rows = ListSubscriber::where('list_id', $list->id)->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(basename($path), $row->import_source);
            $this->assertNotNull($row->imported_at);
        }
    }

    public function test_an_explicit_source_label_overrides_the_filename(): void
    {
        $list = $this->list();

        $report = $this->service->importCsv(
            $this->fixture("email\na@example.com\n"),
            $list,
            source: 'crm-export-batch-7',
        );

        $this->assertSame('crm-export-batch-7', $report->source());
        $this->assertSame(
            'crm-export-batch-7',
            ListSubscriber::where('list_id', $list->id)->value('import_source'),
        );
    }

    // ── Field handling ───────────────────────────────────────────────

    public function test_blank_name_and_locale_become_null_without_invalidating_the_row(): void
    {
        $list = $this->list();

        $report = $this->service->importCsv(
            $this->fixture("email,name,locale\na@example.com,,\n"),
            $list,
        );

        $row = ListSubscriber::where('list_id', $list->id)->firstOrFail();

        $this->assertSame(1, $report->accepted());
        $this->assertNull($row->name);
        $this->assertNull($row->locale);
    }

    public function test_an_over_long_locale_is_dropped_rather_than_failing_the_write(): void
    {
        $list = $this->list();

        $report = $this->service->importCsv(
            $this->fixture("email,name,locale\na@example.com,A,not-a-locale-code-at-all\n"),
            $list,
        );

        $this->assertSame(1, $report->accepted());
        $this->assertNull(ListSubscriber::where('list_id', $list->id)->value('locale'));
    }

    public function test_email_is_stored_normalized(): void
    {
        $list = $this->list();

        $this->service->importCsv($this->fixture("email\n  MiXeD@Example.COM \n"), $list);

        $this->assertSame('mixed@example.com', ListSubscriber::where('list_id', $list->id)->value('email'));
    }

    // ── Dry run ──────────────────────────────────────────────────────

    public function test_dry_run_writes_nothing(): void
    {
        $list = $this->list();

        $report = $this->service->importCsv(
            $this->fixture("email,name\na@example.com,A\nb@example.com,B\n"),
            $list,
            dryRun: true,
        );

        $this->assertTrue($report->isDryRun());
        $this->assertSame(2, $report->accepted());
        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count(), 'nothing persisted');

        $list->refresh();
        $this->assertSame(0, (int) $list->subscriber_count);
    }

    public function test_dry_run_counts_match_a_real_run_across_a_chunk_boundary(): void
    {
        // The reason dry-run is a rolled-back transaction rather than a write-skipping
        // path: cross-chunk duplicate detection needs earlier chunks to be visible, so a
        // write-skipping dry run would report 4 accepted here instead of 3.
        config()->set('autoresponder.import.chunk_size', 2);

        $csv = "email,name\na@example.com,A\nb@example.com,B\nc@example.com,C\na@example.com,A Again\n";

        $dryList = $this->list();
        $dry = $this->service->importCsv($this->fixture($csv), $dryList, dryRun: true);

        $realList = $this->list();
        $real = $this->service->importCsv($this->fixture($csv), $realList);

        $this->assertSame($real->rowsRead(), $dry->rowsRead());
        $this->assertSame($real->accepted(), $dry->accepted());
        $this->assertSame($real->duplicate(), $dry->duplicate());
        $this->assertSame($real->suppressed(), $dry->suppressed());
        $this->assertSame($real->invalid(), $dry->invalid());

        $this->assertSame(3, $dry->accepted());
        $this->assertSame(1, $dry->duplicate());
        $this->assertSame(0, ListSubscriber::where('list_id', $dryList->id)->count());
        $this->assertSame(3, ListSubscriber::where('list_id', $realList->id)->count());
    }

    public function test_dry_run_leaves_an_existing_row_untouched(): void
    {
        $list = $this->list();
        $row = $this->existingRow($list, 'known@example.com', ['name' => 'Old Name']);

        $this->service->importCsv(
            $this->fixture("email,name\nknown@example.com,New Name\n"),
            $list,
            dryRun: true,
        );

        $row->refresh();
        $this->assertSame('Old Name', $row->name);
        $this->assertNull($row->import_source);
    }

    // ── Subscriber count ─────────────────────────────────────────────

    public function test_subscriber_count_matches_the_active_rows_afterwards(): void
    {
        $list = $this->list();
        $this->existingRow($list, 'existing@example.com');
        $this->existingRow($list, 'optedout@example.com', [
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        $report = $this->service->importCsv(
            $this->fixture("email,name\nnew1@example.com,A\nnew2@example.com,B\nbad,C\n"),
            $list,
        );

        $list->refresh();

        $activeRows = ListSubscriber::where('list_id', $list->id)->where('status', 'active')->count();

        $this->assertSame(2, $report->accepted());
        $this->assertSame($activeRows, (int) $list->subscriber_count);
        $this->assertSame(3, $activeRows, 'one pre-existing active row plus two imported');
    }

    // ── Import-level failures ────────────────────────────────────────

    public function test_a_missing_file_fails_the_import_without_writing(): void
    {
        $list = $this->list();

        try {
            $this->service->importCsv('/nonexistent/nope.csv', $list);
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not found or not readable', $e->getMessage());
        }

        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count());
    }

    public function test_a_missing_email_header_fails_the_import_without_writing(): void
    {
        $list = $this->list();

        try {
            $this->service->importCsv($this->fixture("name,locale\nAnn,en\n"), $list);
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not contain the email column', $e->getMessage());
        }

        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count());
    }

    public function test_an_empty_file_fails_the_import(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty or has no header row');

        $this->service->importCsv($this->fixture(''), $this->list());
    }

    public function test_a_dynamic_list_is_not_a_valid_target(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is dynamic; CSV import targets manual lists only');

        $this->service->importCsv(
            $this->fixture("email\na@example.com\n"),
            $this->list('dynamic'),
        );
    }

    public function test_find_list_rejects_an_unknown_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');

        $this->service->findList(987654);
    }

    public function test_find_list_rejects_a_dynamic_list(): void
    {
        $dynamic = $this->list('dynamic');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is dynamic');

        $this->service->findList($dynamic->id);
    }

    public function test_find_list_returns_a_manual_list(): void
    {
        $list = $this->list();

        $this->assertTrue($this->service->findList($list->id)->is($list));
    }

    public function test_create_manual_list_makes_an_active_manual_list(): void
    {
        $list = $this->service->createManualList('Newsletter 2026');

        $this->assertSame('Newsletter 2026', $list->name);
        $this->assertSame('manual', $list->type);
        $this->assertSame('active', $list->status);
    }

    // ── Column mapping ───────────────────────────────────────────────

    public function test_custom_column_names_and_delimiter_are_honoured(): void
    {
        $list = $this->list();

        $report = $this->service->importCsv(
            $this->fixture("\xEF\xBB\xBFe-mail;full name;lang\na@example.com;Ann;ro\n"),
            $list,
            columns: ['email' => 'e-mail', 'name' => 'full name', 'locale' => 'lang'],
            delimiter: ';',
        );

        $row = ListSubscriber::where('list_id', $list->id)->firstOrFail();

        $this->assertSame(1, $report->accepted());
        $this->assertSame('a@example.com', $row->email);
        $this->assertSame('Ann', $row->name);
        $this->assertSame('ro', $row->locale);
    }

    // ── Memory ───────────────────────────────────────────────────────

    public function test_a_large_file_does_not_scale_memory_with_its_length(): void
    {
        // Isolated from database growth on purpose: every row here is invalid, so nothing is
        // written and the measurement reflects only PHP-side memory. That is where the real
        // risk lives — materializing the file, or keeping a global seen-set of addresses.
        config()->set('autoresponder.import.chunk_size', 500);
        config()->set('autoresponder.import.max_rejected_rows', 10);

        $rows = 30000;
        $lines = "email,name\n";
        for ($i = 0; $i < $rows; $i++) {
            $lines .= "not-an-email-{$i},Name {$i}\n";
        }
        $path = $this->fixture($lines);

        $before = memory_get_usage();
        $report = $this->service->importCsv($path, $this->list());
        $growth = memory_get_usage() - $before;

        $this->assertSame($rows, $report->rowsRead());
        $this->assertSame($rows, $report->invalid());
        $this->assertTrue($report->reconciles());
        $this->assertCount(10, $report->rejected(), 'detail stayed capped');
        $this->assertTrue($report->rejectedTruncated());

        // The file alone is ~800KB; holding 30k parsed rows would cost several MB.
        $this->assertLessThan(
            2_000_000,
            $growth,
            "importing {$rows} rows grew memory by {$growth} bytes",
        );
    }
}
