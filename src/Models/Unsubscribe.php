<?php

namespace ColorrageAR\Autoresponder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function ColorrageAR\Autoresponder\ar_table;

class Unsubscribe extends Model
{
    public function getTable(): string
    {
        return ar_table('unsubscribes');
    }

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'unsubscribed_at' => 'datetime',
            'admin_set' => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function subscriber(): BelongsTo
    {
        $model = config('autoresponder.subscriber_model', 'App\\Models\\User');
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->belongsTo($model, 'subscriber_id', $key);
    }
}
