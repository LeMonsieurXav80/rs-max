<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FreeLlmModel extends Model
{
    protected $fillable = [
        'provider',
        'model_id',
        'display_name',
        'supports_text',
        'supports_vision',
        'context_length',
        'daily_token_limit',
        'rpm_limit',
        'metadata',
        'is_available',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'supports_text' => 'boolean',
            'supports_vision' => 'boolean',
            'context_length' => 'integer',
            'daily_token_limit' => 'integer',
            'rpm_limit' => 'integer',
            'metadata' => 'array',
            'is_available' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    public function scopeWithVision(Builder $query): Builder
    {
        return $query->where('supports_vision', true);
    }

    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function getQualifiedNameAttribute(): string
    {
        return $this->provider.'/'.$this->model_id;
    }
}
