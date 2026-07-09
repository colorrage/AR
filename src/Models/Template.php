<?php

namespace ColorrageAR\Autoresponder\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function ColorrageAR\Autoresponder\ar_table;

class Template extends Model
{
    public function getTable(): string
    {
        return ar_table('templates');
    }

    protected $guarded = [];

    // ── Relationships ────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        $model = config('autoresponder.subscriber_model', 'App\\Models\\User');
        $key = config('autoresponder.subscriber_columns.key', 'id');

        return $this->belongsTo($model, 'created_by', $key);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    // ── Computed Attributes ──────────────────────────────────────────

    protected function displayName(): Attribute
    {
        return Attribute::get(function () {
            $locale = $this->locale ? " [{$this->locale}]" : '';

            return $this->name . $locale;
        });
    }

    // ── Accessors ────────────────────────────────────────────────────

    protected function bodyHtml(): Attribute
    {
        return Attribute::get(function ($value) {
            if ($value === null && $this->body !== null) {
                $value = $this->body;
            }

            if ($value === null) {
                return null;
            }

            $decoded = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

            if ($decoded !== $value && str_contains($decoded, '&') && str_contains($decoded, ';')) {
                return html_entity_decode($decoded, ENT_QUOTES, 'UTF-8');
            }

            return $decoded;
        });
    }
}
