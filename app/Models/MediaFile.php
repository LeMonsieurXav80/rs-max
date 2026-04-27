<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFile extends Model
{
    protected $fillable = [
        'folder_id',
        'filename',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'source',
        'source_url',
        'description_fr',
        'thematic_tags',
        'embedding',
        'embedding_model',
        'intimacy_level',
        'people_ids',
        'ai_metadata',
        'source_context',
        'source_path',
        'phash',
        'pending_analysis',
        'ingested_at',
        'city',
        'region',
        'country',
        'brands',
        'event',
        'taken_at',
        'publication_count',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'thematic_tags' => 'array',
        'embedding' => 'array',
        'people_ids' => 'array',
        'ai_metadata' => 'array',
        'brands' => 'array',
        'pending_analysis' => 'boolean',
        'ingested_at' => 'datetime',
        'taken_at' => 'datetime',
        'publication_count' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(MediaPublication::class);
    }

    public function getUrlAttribute(): string
    {
        return "/media/{$this->filename}";
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getIsVideoAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = $this->size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }

    public static function findBySourceUrl(string $url): ?self
    {
        return self::where('source_url', $url)->first();
    }
}
