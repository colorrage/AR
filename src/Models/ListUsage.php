<?php

namespace CmrManagement\Autoresponder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function CmrManagement\Autoresponder\ar_table;

class ListUsage extends Model
{
    public function getTable(): string
    {
        return ar_table('list_usages');
    }

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function mailerList(): BelongsTo
    {
        return $this->belongsTo(MailerList::class, 'list_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
