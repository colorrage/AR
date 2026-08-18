<?php

namespace ColorrageAR\Autoresponder\Services;

use ColorrageAR\Autoresponder\Models\ListSubscriber;
use ColorrageAR\Autoresponder\Models\MailerList;
use ColorrageAR\Autoresponder\Models\Unsubscribe;
use ColorrageAR\Autoresponder\Support\CsvReader;
use ColorrageAR\Autoresponder\Support\ListImportReport;
use ColorrageAR\Autoresponder\Support\RejectedRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function ColorrageAR\Autoresponder\ar_log;

/**
 * Imports a CSV file into a manual mailer list, accounting for every row.
 *
 * Deliberately does NOT use ListService::addSubscriber(): that method never consults
 * ar_unsubscribes and unconditionally flips an existing row back to `active`, which is
 * exactly the opt-out reactivation this importer must never perform. It is also two
 * queries per row.
 *
 * Two properties are load-bearing and easy to break:
 *
 *  - **Every row read is classified into exactly one bucket.** `rowsRead` is counted
 *    separately from the buckets, so `$report->reconciles()` is a genuine check: miss a
 *    classification branch and the identity fails loudly instead of losing a row silently.
 *
 *  - **Explicit opt-outs survive an import.** Beyond checking suppression up front, the
 *    upsert's update-column list omits `status`, `subscribed_at` and `unsubscribed_at`, so
 *    even a row that races in between classification and write keeps its opt-out and
 *    receives only name, locale and provenance.
 */
class ListImportService
{
    /**
     * Maximum bound parameters per lookup. Keeps both reads inside every driver's limit even
     * if a host raises `autoresponder.import.chunk_size`, the way CampaignService chunks its
     * own suppression lookup.
     */
    private const BIND_CHUNK = 500;

    /**
     * @param  array<string, string|null>|null  $columns  Field => CSV header. Defaults to config.
     *
     * @throws RuntimeException on import-level failure, before anything is written.
     */
    public function importCsv(
        string $path,
        MailerList $list,
        ?array $columns = null,
        ?string $delimiter = null,
        ?string $source = null,
        bool $dryRun = false,
    ): ListImportReport {
        $this->assertImportableTarget($list);

        $columns ??= (array) config('autoresponder.import.columns', []);
        $delimiter ??= (string) config('autoresponder.import.delimiter', ',');
        $source ??= basename($path);
        $chunkSize = max(1, (int) config('autoresponder.import.chunk_size', 500));
        $maxRejected = max(0, (int) config('autoresponder.import.max_rejected_rows', 1000));

        $reader = new CsvReader($path, $columns, $delimiter);

        // Fail the whole import here — unreadable file, no header, missing email column —
        // so such a file reports once instead of as a flood of per-row rejections, and
        // before a single row is written.
        $reader->preflight();

        $report = new ListImportReport($source, $dryRun, $maxRejected);

        $run = function () use ($reader, $list, $report, $source, $chunkSize): void {
            $buffer = [];

            foreach ($reader->rows() as $row) {
                // Counted independently of classification; see the class docblock.
                $report->recordRowRead();

                $buffer[] = $row;

                if (count($buffer) >= $chunkSize) {
                    $this->processChunk($buffer, $list, $report, $source);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->processChunk($buffer, $list, $report, $source);
            }

            $list->refreshSubscriberCount();
        };

        if ($dryRun) {
            // The real code path inside a rolled-back transaction, NOT a write-skipping
            // variant. Cross-chunk duplicate detection depends on earlier chunks being
            // visible, so skipping writes would report different counts than a real run.
            // Per-chunk writes nest as savepoints and are discarded with the outer rollback.
            DB::beginTransaction();

            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            $run();
        }

        ar_log()->info('CSV list import complete', [
            'list_id' => $list->id,
            'source' => $source,
            'dry_run' => $dryRun,
            'rows_read' => $report->rowsRead(),
            'accepted' => $report->accepted(),
            'duplicate' => $report->duplicate(),
            'suppressed' => $report->suppressed(),
            'invalid' => $report->invalid(),
            'reconciles' => $report->reconciles(),
        ]);

        return $report;
    }

    /**
     * Look up an existing list to import into.
     *
     * @throws RuntimeException when the list does not exist or cannot receive an import.
     */
    public function findList(int $listId): MailerList
    {
        $list = MailerList::find($listId);

        if ($list === null) {
            throw new RuntimeException("Mailer list [{$listId}] was not found.");
        }

        $this->assertImportableTarget($list);

        return $list;
    }

    /**
     * Create a new manual list to import into.
     */
    public function createManualList(string $name): MailerList
    {
        return MailerList::create([
            'name' => $name,
            'type' => 'manual',
            'status' => 'active',
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────

    private function assertImportableTarget(MailerList $list): void
    {
        if ($list->type === 'dynamic') {
            throw new RuntimeException(
                "Mailer list [{$list->id}] is dynamic; CSV import targets manual lists only."
            );
        }
    }

    /**
     * Classify and write one chunk.
     *
     * Two reads and, in the common case, one write — regardless of chunk contents.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function processChunk(array $rows, MailerList $list, ListImportReport $report, string $source): void
    {
        $candidates = $this->classifyRowShape($rows, $report);

        if ($candidates === []) {
            return;
        }

        $emails = array_keys($candidates);

        $suppressed = $this->globallySuppressed($emails);
        $existing = $this->existingRows($list, $emails);

        $payload = [];
        $refreshes = [];
        $now = now();

        foreach ($candidates as $email => $row) {
            if (isset($suppressed[$email])) {
                $report->recordSuppressed($this->reject(
                    $row,
                    RejectedRow::REASON_SUPPRESSED_GLOBALLY,
                    'Address is globally unsubscribed; an import does not override that.',
                ));

                continue;
            }

            $current = $existing[$email] ?? null;

            if (($current['status'] ?? null) === 'unsubscribed') {
                $report->recordSuppressed($this->reject(
                    $row,
                    RejectedRow::REASON_UNSUBSCRIBED_FROM_LIST,
                    'Address previously unsubscribed from this list; an import is not consent to re-add it.',
                ));

                continue;
            }

            $values = [
                'name' => $this->normalizeName($row['fields']['name'] ?? null),
                'locale' => $this->normalizeLocale($row['fields']['locale'] ?? null),
                'import_source' => $source,
                'imported_at' => $now,
            ];

            if ($current !== null) {
                // Already a subscriber: not a new one, so a duplicate rather than an accept.
                $report->recordDuplicate();
            } else {
                $report->recordAccepted();
            }

            if ($current !== null && $current['email'] !== $email) {
                // A legacy row stored with different casing. Inserting the normalized
                // spelling would create a SECOND row for the same person, because the
                // unique index compares raw values — so refresh the row that really exists.
                $refreshes[$current['email']] = $values;

                continue;
            }

            // New rows and already-normalized existing rows share one upsert. On insert the
            // row lands active; on conflict only name, locale and provenance are touched.
            $payload[] = $values + [
                'list_id' => $list->id,
                'email' => $email,
                'status' => 'active',
                'subscribed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload === [] && $refreshes === []) {
            return;
        }

        DB::transaction(function () use ($payload, $refreshes, $list, $now): void {
            if ($payload !== []) {
                ListSubscriber::query()->upsert(
                    $payload,
                    ['list_id', 'email'],
                    // NEVER add status, subscribed_at or unsubscribed_at here. Their absence
                    // is what makes an opt-out survive a row that races in after
                    // classification.
                    ['name', 'locale', 'import_source', 'imported_at', 'updated_at'],
                );
            }

            foreach ($refreshes as $storedEmail => $values) {
                // Same column set as the upsert's update list, for the same reason.
                ListSubscriber::where('list_id', $list->id)
                    ->where('email', $storedEmail)
                    ->update($values + ['updated_at' => $now]);
            }
        });
    }

    /**
     * Addresses in this batch that are globally unsubscribed, keyed by normalized email.
     *
     * Compared with `LOWER(email)`, matching what `CampaignService` already does for this
     * same table, because the package does not normalize what it stores:
     * `UnsubscribeController::process()` writes whatever `SendLog.to_email` held. A plain
     * `whereIn` against normalized values silently misses a differing-case opt-out on any
     * case-sensitive collation — and then imports a suppressed address.
     *
     * @param  list<string>  $emails
     * @return array<string, int>
     */
    private function globallySuppressed(array $emails): array
    {
        $found = [];

        foreach (array_chunk($emails, self::BIND_CHUNK) as $chunk) {
            foreach (
                Unsubscribe::query()
                    ->whereRaw($this->loweredIn($chunk), $chunk)
                    ->pluck('email') as $email
            ) {
                $found[] = $this->normalizeEmail((string) $email);
            }
        }

        return array_flip($found);
    }

    /**
     * What this list already holds for the addresses in this batch, keyed by normalized
     * email.
     *
     * Matched case-insensitively for the same reason as global suppression: rows written by
     * `ListService::addSubscriber()` or `syncFromModel()` are not normalized. The actual
     * stored spelling is carried alongside the status, because a refresh has to target the
     * row that really exists rather than the normalized spelling.
     *
     * @param  list<string>  $emails
     * @return array<string, array{status: string, email: string}>
     */
    private function existingRows(MailerList $list, array $emails): array
    {
        $existing = [];

        foreach (array_chunk($emails, self::BIND_CHUNK) as $chunk) {
            foreach (
                ListSubscriber::where('list_id', $list->id)
                    ->whereRaw($this->loweredIn($chunk), $chunk)
                    ->get(['email', 'status']) as $row
            ) {
                $key = $this->normalizeEmail((string) $row->email);

                // Dirty legacy data can hold the same address twice under different casing.
                // If either spelling is unsubscribed, that is the one that counts.
                if (($existing[$key]['status'] ?? null) === 'unsubscribed') {
                    continue;
                }

                $existing[$key] = [
                    'status' => (string) $row->status,
                    'email' => (string) $row->email,
                ];
            }
        }

        return $existing;
    }

    /**
     * @param  list<string>  $emails
     */
    private function loweredIn(array $emails): string
    {
        return 'LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')';
    }

    /**
     * Reject unusable rows and drop in-chunk repeats, returning the survivors keyed by
     * normalized email.
     *
     * A repeat that spans chunks needs no in-memory set: the earlier chunk is already
     * written when this one is classified, so the list read above catches it — and
     * "repeated in the file" and "already on the list" are the same `duplicate` bucket.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function classifyRowShape(array $rows, ListImportReport $report): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            if ($row['columnCountMatches'] !== true) {
                $report->recordInvalid($this->reject(
                    $row,
                    RejectedRow::REASON_MALFORMED,
                    'Row does not have the same number of columns as the header.',
                ));

                continue;
            }

            $email = $this->normalizeEmail((string) ($row['fields']['email'] ?? ''));

            if ($email === '') {
                $report->recordInvalid($this->reject(
                    $row,
                    RejectedRow::REASON_MISSING_EMAIL,
                    'Row has no email address.',
                ));

                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $report->recordInvalid($this->reject(
                    $row,
                    RejectedRow::REASON_INVALID_EMAIL,
                    "\"{$email}\" is not a valid email address.",
                ));

                continue;
            }

            if (isset($candidates[$email])) {
                $report->recordDuplicate();

                continue;
            }

            $candidates[$email] = $row;
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function reject(array $row, string $reason, string $message): RejectedRow
    {
        return new RejectedRow(
            line: (int) $row['line'],
            ordinal: (int) $row['ordinal'],
            reason: $reason,
            message: $message,
            values: array_map(
                static fn ($value): ?string => $value === null ? null : (string) $value,
                (array) $row['fields'],
            ),
        );
    }

    /**
     * Normalized once per row and reused for the in-chunk dedup key, both reads, and the
     * stored value — so one address can never fall into two buckets through casing.
     */
    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * `ar_list_subscribers.name` is a 255-character column, so an over-long value is
     * truncated rather than left to abort the write on a strict database. Because the import
     * commits per chunk, an exception there would land mid-file with earlier chunks already
     * committed and no report returned at all — destroying the accounting this feature exists
     * to provide. SQLite accepts the over-long value silently, which is why this needs to be
     * explicit rather than left to the database.
     */
    private function normalizeName(?string $value): ?string
    {
        $name = $this->blankToNull($value);

        if ($name === null) {
            return null;
        }

        return mb_strlen($name) > 255 ? mb_substr($name, 0, 255) : $name;
    }

    /**
     * The locale column is 10 characters. Anything longer is not a locale code, so it is
     * dropped rather than allowed to fail the write on a strict database — the spec says an
     * unrecognized locale imports as null and does not invalidate the row.
     */
    private function normalizeLocale(?string $value): ?string
    {
        $locale = $this->blankToNull($value);

        if ($locale === null) {
            return null;
        }

        return mb_strlen($locale) > 10 ? null : $locale;
    }
}
