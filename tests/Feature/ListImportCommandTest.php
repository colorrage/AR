<?php

namespace ColorrageAR\Autoresponder\Tests\Feature;

use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ListImportCommandTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'ar_cmd_') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function manualList(string $name = 'Existing List'): MailerList
    {
        return MailerList::create(['name' => $name, 'type' => 'manual', 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{code: int, output: string}
     */
    private function importCommand(array $options): array
    {
        $code = Artisan::call('autoresponder:import-list', $options);

        return ['code' => $code, 'output' => Artisan::output()];
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('autoresponder:import-list', Artisan::all());
    }

    // ── Successful imports ───────────────────────────────────────────

    public function test_it_imports_into_a_newly_created_list(): void
    {
        $result = $this->importCommand([
            'file' => $this->fixture("email,name\na@example.com,Ann\nb@example.com,Bob\n"),
            '--create' => 'Newsletter 2026',
        ]);

        $this->assertSame(0, $result['code']);

        $list = MailerList::where('name', 'Newsletter 2026')->firstOrFail();
        $this->assertSame('manual', $list->type);
        $this->assertSame(2, ListSubscriber::where('list_id', $list->id)->count());

        $this->assertStringContainsString('Rows read', $result['output']);
        $this->assertStringContainsString('Reconciled', $result['output']);
    }

    public function test_it_imports_into_an_existing_list(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("email,name\na@example.com,Ann\n"),
            '--list' => $list->id,
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count());
    }

    public function test_the_counts_appear_in_the_output(): void
    {
        $list = $this->manualList();
        ListSubscriber::create([
            'list_id' => $list->id,
            'email' => 'known@example.com',
            'status' => 'active',
            'subscribed_at' => now(),
        ]);
        Unsubscribe::create(['email' => 'blocked@example.com', 'unsubscribed_at' => now()]);

        $result = $this->importCommand([
            'file' => $this->fixture(
                "email,name\nnew@example.com,New\nknown@example.com,Known\nblocked@example.com,Blocked\nbad,Bad\n"
            ),
            '--list' => $list->id,
        ]);

        $this->assertSame(0, $result['code']);
        // 4 rows read: 1 accepted, 1 duplicate, 1 suppressed, 1 invalid.
        $this->assertMatchesRegularExpression('/\b4\b.*\b1\b.*\b1\b.*\b1\b.*\b1\b/s', $result['output']);
        $this->assertStringContainsString('Reconciled', $result['output']);
    }

    // ── Rejected rows are not a command failure ──────────────────────

    public function test_a_file_with_rejected_rows_still_succeeds(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("email,name\nok@example.com,Fine\nnot-an-email,Bad\n,Blank\n"),
            '--list' => $list->id,
        ]);

        $this->assertSame(0, $result['code'], 'rejected rows are a normal outcome, not a failure');
        $this->assertSame(1, ListSubscriber::where('list_id', $list->id)->count());

        $this->assertStringContainsString('rejected or skipped', $result['output']);
        $this->assertStringContainsString('invalid_email', $result['output']);
        $this->assertStringContainsString('missing_email', $result['output']);
    }

    public function test_rejected_rows_are_listed_with_their_file_line_numbers(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("email,name\nok@example.com,Fine\nnot-an-email,Bad\n"),
            '--list' => $list->id,
        ]);

        // The bad row is on physical line 3.
        $this->assertMatchesRegularExpression('/\|\s*3\s*\|/', $result['output']);
    }

    public function test_a_truncated_rejection_list_says_so(): void
    {
        // A short list that silently omitted rows would read as complete — the failure mode
        // worth guarding here.
        config()->set('autoresponder.import.max_rejected_rows', 2);

        $list = $this->manualList();

        $rows = "email,name\n";
        for ($i = 0; $i < 6; $i++) {
            $rows .= "bad-address-{$i},Name {$i}\n";
        }

        $result = $this->importCommand(['file' => $this->fixture($rows), '--list' => $list->id]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('truncated', $result['output']);
        $this->assertStringContainsString('only the first 2 of 6', $result['output']);
    }

    // ── Dry run ──────────────────────────────────────────────────────

    public function test_dry_run_reports_without_writing(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("email,name\na@example.com,Ann\nb@example.com,Bob\n"),
            '--list' => $list->id,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count(), 'nothing written');
        $this->assertStringContainsString('DRY RUN', $result['output']);
        $this->assertStringContainsString('Rows read', $result['output']);
    }

    public function test_dry_run_with_create_does_not_leave_a_list_behind(): void
    {
        // The subtle one: the list creation itself must be rolled back too, or a dry run
        // leaves a stray empty list every time it is used.
        $before = MailerList::count();

        $result = $this->importCommand([
            'file' => $this->fixture("email,name\na@example.com,Ann\n"),
            '--create' => 'Should Not Persist',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame($before, MailerList::count(), 'no list was created');
        $this->assertNull(MailerList::where('name', 'Should Not Persist')->first());
        $this->assertStringContainsString('DRY RUN', $result['output']);
    }

    // ── Options ──────────────────────────────────────────────────────

    public function test_custom_column_options_are_honoured(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("e-mail,full name,lang\na@example.com,Ann,ro\n"),
            '--list' => $list->id,
            '--email-column' => 'e-mail',
            '--name-column' => 'full name',
            '--locale-column' => 'lang',
        ]);

        $this->assertSame(0, $result['code']);

        $row = ListSubscriber::where('list_id', $list->id)->firstOrFail();
        $this->assertSame('a@example.com', $row->email);
        $this->assertSame('Ann', $row->name);
        $this->assertSame('ro', $row->locale);
    }

    public function test_a_semicolon_delimited_file_imports(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("\xEF\xBB\xBFemail;name\na@example.com;Ann\n"),
            '--list' => $list->id,
            '--delimiter' => ';',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame('a@example.com', ListSubscriber::where('list_id', $list->id)->value('email'));
    }

    public function test_an_explicit_source_label_is_stored(): void
    {
        $list = $this->manualList();

        $this->importCommand([
            'file' => $this->fixture("email\na@example.com\n"),
            '--list' => $list->id,
            '--source' => 'crm-batch-7',
        ]);

        $this->assertSame('crm-batch-7', ListSubscriber::where('list_id', $list->id)->value('import_source'));
    }

    public function test_the_source_defaults_to_the_file_basename(): void
    {
        $list = $this->manualList();
        $path = $this->fixture("email\na@example.com\n");

        $this->importCommand(['file' => $path, '--list' => $list->id]);

        $this->assertSame(
            basename($path),
            ListSubscriber::where('list_id', $list->id)->value('import_source'),
        );
    }

    // ── Import-level failures ────────────────────────────────────────

    public function test_a_missing_file_fails(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand(['file' => '/nonexistent/nope.csv', '--list' => $list->id]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('not found or not readable', $result['output']);
    }

    public function test_a_missing_email_header_fails(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("name,locale\nAnn,en\n"),
            '--list' => $list->id,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('does not contain the email column', $result['output']);
    }

    public function test_an_unknown_list_id_fails(): void
    {
        $result = $this->importCommand([
            'file' => $this->fixture("email\na@example.com\n"),
            '--list' => 987654,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('was not found', $result['output']);
    }

    public function test_a_dynamic_list_target_fails(): void
    {
        $dynamic = MailerList::create(['name' => 'Dynamic', 'type' => 'dynamic', 'status' => 'active']);

        $result = $this->importCommand([
            'file' => $this->fixture("email\na@example.com\n"),
            '--list' => $dynamic->id,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('is dynamic', $result['output']);
    }

    public function test_neither_list_nor_create_is_a_usage_error(): void
    {
        $result = $this->importCommand(['file' => $this->fixture("email\na@example.com\n")]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('exactly one of', $result['output']);
    }

    public function test_both_list_and_create_is_a_usage_error(): void
    {
        $list = $this->manualList();

        $result = $this->importCommand([
            'file' => $this->fixture("email\na@example.com\n"),
            '--list' => $list->id,
            '--create' => 'Also This',
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('exactly one of', $result['output']);
        $this->assertNull(MailerList::where('name', 'Also This')->first(), 'no list created on usage error');
    }

    public function test_an_import_level_failure_writes_nothing(): void
    {
        $list = $this->manualList();

        $this->importCommand(['file' => $this->fixture("name\nAnn\n"), '--list' => $list->id]);

        $this->assertSame(0, ListSubscriber::where('list_id', $list->id)->count());
    }
}
