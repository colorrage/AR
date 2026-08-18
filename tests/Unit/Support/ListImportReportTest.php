<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Support;

use ColorrageAR\Autoresponder\Support\ListImportReport;
use ColorrageAR\Autoresponder\Support\RejectedRow;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit, not the package TestCase: these objects read no config and touch no
 * database, and this test proves it by never booting an application.
 */
class ListImportReportTest extends TestCase
{
    private function rejectedRow(int $ordinal, string $reason = RejectedRow::REASON_INVALID_EMAIL): RejectedRow
    {
        return new RejectedRow(
            line: $ordinal + 1,
            ordinal: $ordinal,
            reason: $reason,
            message: 'not a valid address',
            values: ['email' => 'nope'],
        );
    }

    public function test_a_fully_classified_report_reconciles(): void
    {
        $report = new ListImportReport('contacts.csv');

        $report->recordRowRead(6);
        $report->recordAccepted(3);
        $report->recordDuplicate();
        $report->recordSuppressed($this->rejectedRow(5, RejectedRow::REASON_SUPPRESSED_GLOBALLY));
        $report->recordInvalid($this->rejectedRow(6));

        $this->assertSame(6, $report->rowsRead());
        $this->assertSame(3, $report->accepted());
        $this->assertSame(1, $report->duplicate());
        $this->assertSame(1, $report->suppressed());
        $this->assertSame(1, $report->invalid());
        $this->assertTrue($report->reconciles());
    }

    public function test_a_missed_classification_fails_reconciliation(): void
    {
        // This is the failure the identity exists to catch: six rows read, five classified.
        // If reconciles() were derived from the buckets it could never detect this.
        $report = new ListImportReport('contacts.csv');

        $report->recordRowRead(6);
        $report->recordAccepted(5);

        $this->assertFalse($report->reconciles());
    }

    public function test_an_empty_report_reconciles(): void
    {
        $report = new ListImportReport('empty.csv');

        $this->assertSame(0, $report->rowsRead());
        $this->assertTrue($report->reconciles());
    }

    public function test_detail_is_capped_while_counts_stay_exact(): void
    {
        $report = new ListImportReport('big.csv', maxRejectedRows: 3);

        for ($i = 1; $i <= 10; $i++) {
            $report->recordRowRead();
            $report->recordInvalid($this->rejectedRow($i));
        }

        $this->assertCount(3, $report->rejected(), 'detail is bounded');
        $this->assertSame(10, $report->invalid(), 'counts are not');
        $this->assertSame(10, $report->rowsRead());
        $this->assertTrue($report->rejectedTruncated());
        $this->assertTrue($report->reconciles());
    }

    public function test_truncation_flag_stays_false_below_the_cap(): void
    {
        $report = new ListImportReport('small.csv', maxRejectedRows: 3);

        $report->recordRowRead(2);
        $report->recordInvalid($this->rejectedRow(1));
        $report->recordInvalid($this->rejectedRow(2));

        $this->assertCount(2, $report->rejected());
        $this->assertFalse($report->rejectedTruncated());
    }

    public function test_the_cap_is_shared_across_invalid_and_suppressed_detail(): void
    {
        $report = new ListImportReport('mixed.csv', maxRejectedRows: 2);

        $report->recordRowRead(3);
        $report->recordInvalid($this->rejectedRow(1));
        $report->recordSuppressed($this->rejectedRow(2, RejectedRow::REASON_SUPPRESSED_GLOBALLY));
        $report->recordSuppressed($this->rejectedRow(3, RejectedRow::REASON_UNSUBSCRIBED_FROM_LIST));

        $this->assertCount(2, $report->rejected());
        $this->assertTrue($report->rejectedTruncated());
        $this->assertSame(1, $report->invalid());
        $this->assertSame(2, $report->suppressed());
        $this->assertSame(3, $report->rejectedCount());
    }

    public function test_counts_advance_without_detail(): void
    {
        // A caller may classify without supplying detail; the count must still move.
        $report = new ListImportReport('nodetail.csv');

        $report->recordRowRead(2);
        $report->recordInvalid();
        $report->recordSuppressed();

        $this->assertSame(1, $report->invalid());
        $this->assertSame(1, $report->suppressed());
        $this->assertSame([], $report->rejected());
        $this->assertFalse($report->rejectedTruncated());
        $this->assertTrue($report->reconciles());
    }

    public function test_every_reason_code_is_representable(): void
    {
        $reasons = [
            RejectedRow::REASON_MALFORMED,
            RejectedRow::REASON_MISSING_EMAIL,
            RejectedRow::REASON_INVALID_EMAIL,
            RejectedRow::REASON_SUPPRESSED_GLOBALLY,
            RejectedRow::REASON_UNSUBSCRIBED_FROM_LIST,
        ];

        $report = new ListImportReport('reasons.csv');

        foreach ($reasons as $i => $reason) {
            $report->recordRowRead();
            $report->recordInvalid($this->rejectedRow($i + 1, $reason));
        }

        $this->assertSame($reasons, array_map(
            static fn (RejectedRow $row): string => $row->reason,
            $report->rejected(),
        ));
    }

    public function test_source_and_dry_run_are_carried(): void
    {
        $report = new ListImportReport('contacts-2026-08.csv', dryRun: true);

        $this->assertSame('contacts-2026-08.csv', $report->source());
        $this->assertTrue($report->isDryRun());
    }

    public function test_array_serialization_carries_every_rendered_field(): void
    {
        $report = new ListImportReport('contacts.csv', dryRun: true, maxRejectedRows: 1);

        $report->recordRowRead(4);
        $report->recordAccepted();
        $report->recordDuplicate();
        $report->recordInvalid($this->rejectedRow(3, RejectedRow::REASON_MALFORMED));
        $report->recordSuppressed($this->rejectedRow(4, RejectedRow::REASON_SUPPRESSED_GLOBALLY));

        $array = $report->toArray();

        $this->assertSame('contacts.csv', $array['source']);
        $this->assertTrue($array['dry_run']);
        $this->assertSame(4, $array['rows_read']);
        $this->assertSame(1, $array['accepted']);
        $this->assertSame(1, $array['duplicate']);
        $this->assertSame(1, $array['suppressed']);
        $this->assertSame(1, $array['invalid']);
        $this->assertSame(2, $array['rejected_count']);
        $this->assertTrue($array['rejected_truncated']);
        $this->assertTrue($array['reconciles']);

        $this->assertCount(1, $array['rejected']);
        $this->assertSame(
            ['line', 'ordinal', 'reason', 'message', 'values'],
            array_keys($array['rejected'][0]),
        );
        $this->assertSame(RejectedRow::REASON_MALFORMED, $array['rejected'][0]['reason']);
    }

    public function test_rejected_detail_cannot_be_mutated_through_the_accessor(): void
    {
        $report = new ListImportReport('contacts.csv');

        $report->recordRowRead();
        $report->recordInvalid($this->rejectedRow(1));

        $rows = $report->rejected();
        $rows[] = $this->rejectedRow(2);

        $this->assertCount(1, $report->rejected(), 'the accessor returns a copy');
    }
}
