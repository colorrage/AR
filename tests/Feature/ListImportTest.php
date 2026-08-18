<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Jobs\SendSingleCampaignEmail;
use ColorrageAR\Autoresponder\Mail\AutoresponderMail;
use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\SendLog;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Services\CampaignService;
use ColorrageAR\Autoresponder\Services\ListImportService;
use ColorrageAR\Autoresponder\Services\TokenService;
use ColorrageAR\Autoresponder\Support\ListImportReport;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * The promise, end to end: a spreadsheet becomes a mailing list you can actually send to,
 * and every row in the file is accounted for.
 *
 * T5.4 proves each classification branch in isolation. This proves the whole thing, on files
 * shaped like the ones an operator really uploads.
 */
class ListImportTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

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
        $path = tempnam(sys_get_temp_dir(), 'ar_feature_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function import(string $contents, ?MailerList $list = null, array $args = []): array
    {
        $list ??= app(ListImportService::class)->createManualList('Imported From CSV');

        $report = app(ListImportService::class)->importCsv(
            $this->fixture($contents),
            $list,
            $args['columns'] ?? null,
            $args['delimiter'] ?? null,
            $args['source'] ?? null,
        );

        return [$report, $list->fresh()];
    }

    // ── Proof 1: a CSV file becomes a sendable list ──────────────────

    public function test_an_imported_list_is_sendable_by_the_campaign_path(): void
    {
        Queue::fake();

        [$report, $list] = $this->import(
            "email,name,locale\n"
            . "ann@example.com,Ann,en\n"
            . "bob@example.com,Bob,en\n"
            . "cid@example.com,Cid,en\n"
        );

        $campaign = $this->makeListCampaign($list);
        app(CampaignService::class)->sendCampaign($campaign);

        $logs = SendLog::where('campaign_id', $campaign->id)->orderBy('id')->get();

        $this->assertCount(3, $logs, 'one send log per imported subscriber');
        $this->assertSame(
            ['ann@example.com', 'bob@example.com', 'cid@example.com'],
            $logs->pluck('email')->sort()->values()->all(),
        );

        // Drive one delivery by hand to prove the address really receives mail.
        (new SendSingleCampaignEmail($logs->first()->id))->handle(app(TokenService::class));

        Mail::assertSent(AutoresponderMail::class);
        $this->assertSame('sent', SendLog::find($logs->first()->id)->status);

        $this->assertSame(3, $report->accepted());
    }

    public function test_three_independently_computed_totals_agree(): void
    {
        // The report's count, the list's cached column, and the actual rows are produced by
        // three different code paths. Agreement between them is a much stronger statement
        // than any one of them alone.
        [$report, $list] = $this->import(
            "email,name\n"
            . "a@example.com,A\n"
            . "b@example.com,B\n"
            . "c@example.com,C\n"
            . "d@example.com,D\n"
        );

        $activeRows = ListSubscriber::where('list_id', $list->id)->where('status', 'active')->count();

        $this->assertSame(4, $report->accepted());
        $this->assertSame(4, (int) $list->subscriber_count);
        $this->assertSame(4, $activeRows);
    }

    // ── Proof 2: reconciliation on one realistic file ────────────────

    public function test_every_bucket_at_once_reconciles(): void
    {
        $list = app(ListImportService::class)->createManualList('Mixed Bag');

        ListSubscriber::create([
            'list_id' => $list->id,
            'email' => 'known@example.com',
            'name' => 'Known Already',
            'status' => 'active',
            'subscribed_at' => now()->subMonth(),
        ]);
        ListSubscriber::create([
            'list_id' => $list->id,
            'email' => 'optedout@example.com',
            'status' => 'unsubscribed',
            'subscribed_at' => now()->subMonth(),
            'unsubscribed_at' => now()->subWeek(),
        ]);
        Unsubscribe::create(['email' => 'global@example.com', 'unsubscribed_at' => now()->subYear()]);

        // 12 data rows: 4 accepted, 3 duplicate, 2 suppressed, 3 invalid.
        $csv = "email,name,locale\n"
            . "fresh1@example.com,Fresh One,en\n"          // accepted
            . "fresh2@example.com,Fresh Two,ro\n"          // accepted
            . "fresh3@example.com,Fresh Three,\n"          // accepted
            . "fresh4@example.com,Fresh Four,en\n"         // accepted
            . "fresh1@example.com,Repeat,en\n"             // duplicate (in file)
            . "FRESH2@EXAMPLE.COM,Repeat Cased,en\n"       // duplicate (in file, different case)
            . "known@example.com,Known Refreshed,en\n"     // duplicate (already active)
            . "optedout@example.com,Come Back,en\n"        // suppressed (per-list opt-out)
            . "global@example.com,Blocked,en\n"            // suppressed (global)
            . "definitely-not-an-email,Bad Format,en\n"    // invalid
            . ",No Address At All,en\n"                    // invalid
            . "shortrow@example.com,Missing Locale\n";     // invalid (column count)

        [$report] = $this->import($csv, $list);

        $this->assertSame(12, $report->rowsRead());
        $this->assertSame(4, $report->accepted());
        $this->assertSame(3, $report->duplicate());
        $this->assertSame(2, $report->suppressed());
        $this->assertSame(3, $report->invalid());
        $this->assertTrue($report->reconciles(), 'no silent row loss');

        // And the state that matters, not just the counts.
        $this->assertSame(
            'unsubscribed',
            ListSubscriber::where('list_id', $list->id)->where('email', 'optedout@example.com')->value('status'),
            'the per-list opt-out survived',
        );
        $this->assertSame(
            0,
            ListSubscriber::where('list_id', $list->id)->where('email', 'global@example.com')->count(),
            'the globally suppressed address was never added',
        );
        $this->assertSame(
            'Known Refreshed',
            ListSubscriber::where('list_id', $list->id)->where('email', 'known@example.com')->value('name'),
            'the already-known row was refreshed rather than duplicated',
        );
    }

    public function test_the_reconciliation_identity_holds_for_a_file_of_nothing_but_rejects(): void
    {
        [$report, $list] = $this->import(
            "email,name\nnope,One\nalso-nope,Two\n,Three\n"
        );

        $this->assertSame(3, $report->rowsRead());
        $this->assertSame(3, $report->invalid());
        $this->assertSame(0, $report->accepted());
        $this->assertTrue($report->reconciles());
        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count());
        $this->assertSame(0, (int) $list->subscriber_count);
    }

    // ── Real-world file shapes ──────────────────────────────────────

    public function test_a_european_excel_export_imports(): void
    {
        // BOM + semicolons + headings that are not the defaults. This is the single
        // combination most likely to arrive first from a real operator, and each part of it
        // breaks a naive importer on its own.
        [$report, $list] = $this->import(
            "\xEF\xBB\xBFAdresa de e-mail;Nume complet;Limba\n"
            . "ion@example.ro;Ion Popescu;ro\n"
            . "maria@example.ro;Maria Ionescu;ro\n",
            null,
            [
                'columns' => ['email' => 'Adresa de e-mail', 'name' => 'Nume complet', 'locale' => 'Limba'],
                'delimiter' => ';',
            ],
        );

        $this->assertSame(2, $report->accepted());
        $this->assertTrue($report->reconciles());

        $rows = ListSubscriber::where('list_id', $list->id)->orderBy('email')->get();
        $this->assertSame(['ion@example.ro', 'maria@example.ro'], $rows->pluck('email')->all());
        $this->assertSame(['Ion Popescu', 'Maria Ionescu'], $rows->pluck('name')->all());
        $this->assertSame(['ro', 'ro'], $rows->pluck('locale')->all());
    }

    public function test_quoted_fields_survive_the_round_trip_into_the_database(): void
    {
        [$report, $list] = $this->import(
            "email,name\n"
            . "a@example.com,\"Doe, John\"\n"
            . "b@example.com,\"Say \"\"hello\"\"\"\n"
            . "c@example.com,\"Two\nLines\"\n"
        );

        $this->assertSame(3, $report->accepted());
        $this->assertTrue($report->reconciles());

        $names = ListSubscriber::where('list_id', $list->id)->orderBy('email')->pluck('name', 'email')->all();

        $this->assertSame('Doe, John', $names['a@example.com']);
        $this->assertSame('Say "hello"', $names['b@example.com']);
        $this->assertSame("Two\nLines", $names['c@example.com']);
    }

    public function test_rejected_rows_point_at_the_right_physical_line_even_with_embedded_newlines(): void
    {
        // line 2: record 1, spans lines 2-3
        // line 4: record 2 — the bad one
        [$report] = $this->import(
            "email,name\n"
            . "ok@example.com,\"Two\nLines\"\n"
            . "bad-address,Nope\n"
        );

        $this->assertSame(1, $report->invalid());
        $this->assertSame(4, $report->rejected()[0]->line, 'the line an operator would open the file at');
        $this->assertSame(2, $report->rejected()[0]->ordinal, 'the data-record number a test asserts on');
    }

    // ── Provenance ──────────────────────────────────────────────────

    public function test_every_imported_row_records_its_provenance(): void
    {
        [, $list] = $this->import(
            "email,name\na@example.com,A\nb@example.com,B\n",
            null,
            ['source' => 'crm-export-2026-08.csv'],
        );

        $rows = ListSubscriber::where('list_id', $list->id)->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('crm-export-2026-08.csv', $row->import_source);
            $this->assertNotNull($row->imported_at);
        }
    }

    // ── Two imports in sequence ─────────────────────────────────────

    public function test_a_second_import_adds_without_disturbing_the_first(): void
    {
        // The realistic operating pattern, and the one where pruning would do damage.
        [$first, $list] = $this->import("email,name\na@example.com,A\nb@example.com,B\n");

        [$second, $list] = $this->import(
            "email,name\nb@example.com,B Updated\nc@example.com,C\n",
            $list,
            ['source' => 'second-batch.csv'],
        );

        $this->assertSame(2, $first->accepted());
        $this->assertSame(1, $second->accepted(), 'only c@ is new');
        $this->assertSame(1, $second->duplicate(), 'b@ was already there');

        $rows = ListSubscriber::where('list_id', $list->id)->orderBy('email')->get()->keyBy('email');

        $this->assertCount(3, $rows);
        $this->assertSame('active', $rows['a@example.com']->status, 'absent from the second file, untouched');
        $this->assertSame('B Updated', $rows['b@example.com']->name, 'present in both, refreshed');
        $this->assertSame('second-batch.csv', $rows['b@example.com']->import_source, 'provenance follows the refresh');
        $this->assertSame(3, (int) $list->subscriber_count);
    }

    // ── The report is the shared contract ───────────────────────────

    public function test_the_report_serializes_for_a_host_to_render(): void
    {
        [$report] = $this->import("email,name\na@example.com,A\nbad,B\n");

        $this->assertInstanceOf(ListImportReport::class, $report);

        $array = $report->toArray();

        $this->assertSame(2, $array['rows_read']);
        $this->assertSame(1, $array['accepted']);
        $this->assertSame(1, $array['invalid']);
        $this->assertTrue($array['reconciles']);
        $this->assertCount(1, $array['rejected']);
        $this->assertSame(3, $array['rejected'][0]['line']);
    }
}
