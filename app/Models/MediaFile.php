<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'thumbnail_path',
        'source',
        'is_generated',
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
        'is_generated' => 'boolean',
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

    /**
     * Marques / partenaires tagues sur la photo. Source de verite : ce pivot.
     * La colonne `brands` en est le miroir denormalise (noms canoniques), tenu
     * a jour par PartnerTagService et conserve pour les prompts IA et l'API.
     */
    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'media_file_partner')->withTimestamps();
    }

    /**
     * Photos du catalogue employées pour fabriquer CETTE image (slots image de la
     * brique). Vide pour un fichier importé normalement.
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'media_derivations', 'derived_media_file_id', 'source_media_file_id')
            ->withPivot(['slot', 'brick', 'match_method', 'match_confidence'])
            ->withTimestamps();
    }

    /**
     * Images générées fabriquées À PARTIR de cette photo (l'inverse de sources()).
     */
    public function derivatives(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'media_derivations', 'source_media_file_id', 'derived_media_file_id')
            ->withPivot(['slot', 'brick', 'match_method', 'match_confidence'])
            ->withTimestamps();
    }

    public function getUrlAttribute(): string
    {
        return "/media/{$this->filename}";
    }

    /**
     * URL de la vignette légère (image ET vidéo). Pointe toujours vers la route
     * media.thumbnail, qui sert la vignette pré-générée ou la génère à la volée.
     */
    public function getThumbnailUrlAttribute(): string
    {
        return route('media.thumbnail', $this->filename);
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
