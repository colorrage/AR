<?php

namespace ColorrageAR\Autoresponder\Support;

/**
 * One row an operator can act on: rejected as unusable, or skipped because the address
 * is suppressed.
 *
 * Duplicates are deliberately not represented here. They are counted but never detailed —
 * an already-known address is not something the operator needs to fix.
 */
final class RejectedRow
{
    /** Column count did not match the header. */
    public const REASON_MALFORMED = 'malformed_row';

    /** The email cell was absent or blank. */
    public const REASON_MISSING_EMAIL = 'missing_email';

    /** The email value failed validation. */
    public const REASON_INVALID_EMAIL = 'invalid_email';

    /** The address is in the global unsubscribe table. */
    public const REASON_SUPPRESSED_GLOBALLY = 'suppressed_globally';

    /** The address is already on this list with status `unsubscribed`. */
    public const REASON_UNSUBSCRIBED_FROM_LIST = 'unsubscribed_from_list';

    /**
     * @param  int  $line  1-based physical line in the file where the record starts.
     * @param  int  $ordinal  1-based data-record number, header excluded. Diverges from
     *                        `$line` once a quoted field contains a newline.
     * @param  string  $reason  One of the REASON_* constants.
     * @param  string  $message  Human-readable explanation for the operator.
     * @param  array<string, string|null>  $values  The row's mapped values, for display.
     */
    public function __construct(
        public readonly int $line,
        public readonly int $ordinal,
        public readonly string $reason,
        public readonly string $message,
        public readonly array $values = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'ordinal' => $this->ordinal,
            'reason' => $this->reason,
            'message' => $this->message,
            'values' => $this->values,
        ];
    }
}
