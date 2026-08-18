<?php

namespace ColorrageAR\Autoresponder\Support;

/**
 * The result of one CSV import: what happened to every row.
 *
 * This is the structured value all three call sites share — the console command, the
 * Filament stub, and the tests — so none of them has to re-derive counts from the database.
 *
 * Accumulation is deliberate rather than incidental. `rowsRead` is counted independently of
 * the four buckets, by the caller, once per record it reads. That is what makes
 * `reconciles()` a real check instead of a tautology: if a classification branch is ever
 * missed, the sum stops matching and the import says so, which is the whole point of "no
 * silent row loss".
 *
 * Reads no config: the rejected-detail cap is injected, so this stays constructible in a
 * unit test without booting an application.
 */
final class ListImportReport
{
    private int $rowsRead = 0;

    private int $accepted = 0;

    private int $duplicate = 0;

    private int $suppressed = 0;

    private int $invalid = 0;

    /** @var list<RejectedRow> */
    private array $rejected = [];

    private bool $rejectedTruncated = false;

    /**
     * @param  string  $source  Provenance label stored on each written row.
     * @param  bool  $dryRun  Whether writes were rolled back.
     * @param  int  $maxRejectedRows  Cap on retained per-row detail. Counts stay exact
     *                                regardless; only the detail list is bounded.
     */
    public function __construct(
        private readonly string $source,
        private readonly bool $dryRun = false,
        private readonly int $maxRejectedRows = 1000,
    ) {}

    // ── Accumulation ─────────────────────────────────────────────────

    /**
     * Call once per data record read from the file, before classifying it.
     */
    public function recordRowRead(int $count = 1): void
    {
        $this->rowsRead += $count;
    }

    public function recordAccepted(int $count = 1): void
    {
        $this->accepted += $count;
    }

    public function recordDuplicate(int $count = 1): void
    {
        $this->duplicate += $count;
    }

    public function recordSuppressed(?RejectedRow $row = null): void
    {
        $this->suppressed++;
        $this->addDetail($row);
    }

    public function recordInvalid(?RejectedRow $row = null): void
    {
        $this->invalid++;
        $this->addDetail($row);
    }

    // ── Reading ──────────────────────────────────────────────────────

    public function source(): string
    {
        return $this->source;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function rowsRead(): int
    {
        return $this->rowsRead;
    }

    public function accepted(): int
    {
        return $this->accepted;
    }

    public function duplicate(): int
    {
        return $this->duplicate;
    }

    public function suppressed(): int
    {
        return $this->suppressed;
    }

    public function invalid(): int
    {
        return $this->invalid;
    }

    /**
     * @return list<RejectedRow>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    public function rejectedTruncated(): bool
    {
        return $this->rejectedTruncated;
    }

    /**
     * Total rows the operator may want to act on. Not a fifth bucket — `invalid` and
     * `suppressed` are already counted separately.
     */
    public function rejectedCount(): int
    {
        return $this->invalid + $this->suppressed;
    }

    /**
     * The acceptance proof: every row read landed in exactly one bucket.
     */
    public function reconciles(): bool
    {
        return $this->accepted + $this->duplicate + $this->suppressed + $this->invalid === $this->rowsRead;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'dry_run' => $this->dryRun,
            'rows_read' => $this->rowsRead,
            'accepted' => $this->accepted,
            'duplicate' => $this->duplicate,
            'suppressed' => $this->suppressed,
            'invalid' => $this->invalid,
            'rejected_count' => $this->rejectedCount(),
            'rejected_truncated' => $this->rejectedTruncated,
            'reconciles' => $this->reconciles(),
            'rejected' => array_map(static fn (RejectedRow $row): array => $row->toArray(), $this->rejected),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * Cap enforcement lives here rather than at each call site, so no caller can bypass it.
     */
    private function addDetail(?RejectedRow $row): void
    {
        if ($row === null) {
            return;
        }

        if (count($this->rejected) >= $this->maxRejectedRows) {
            $this->rejectedTruncated = true;

            return;
        }

        $this->rejected[] = $row;
    }
}
