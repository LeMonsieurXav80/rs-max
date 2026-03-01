<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hook extends Model
{
    protected $fillable = [
        'hook_category_id',
        'content',
        'is_active',
        'times_used',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HookCategory::class, 'hook_category_id');
    }

    /**
     * Pick the least-used active hook for a given category.
     * Ties broken by last_used_at (oldest first, nulls first).
     */
    public static function pickForCategory(int $categoryId): ?self
    {
        $hook = static::where('hook_category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('times_used')
            ->orderByRaw('last_used_at IS NOT NULL, last_used_at')
            ->first();

        if ($hook) {
            $hook->increment('times_used');
            $hook->update(['last_used_at' => now()]);
        }

        return $hook;
    }
}
