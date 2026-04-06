<?php

namespace CmrManagement\Autoresponder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function CmrManagement\Autoresponder\ar_table;

class ListFilter extends Model
{
    public function getTable(): string
    {
        return ar_table('list_filters');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filter_config' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function mailerList(): BelongsTo
    {
        return $this->belongsTo(MailerList::class, 'list_id');
    }
}
