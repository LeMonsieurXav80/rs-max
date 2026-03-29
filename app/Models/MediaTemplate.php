<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'format',
        'width',
        'height',
        'layout',
        'title_font',
        'title_font_weight',
        'title_font_size',
        'body_font',
        'body_font_weight',
        'body_font_size',
        'colors',
        'border',
        'config',
        'preview_path',
        'is_active',
    ];

    protected $casts = [
        'colors' => 'array',
        'border' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public const FORMATS = [
        'pinterest_pin' => ['label' => 'Pinterest Pin', 'width' => 1000, 'height' => 1500],
        'instagram_post_square' => ['label' => 'Instagram Post (carré)', 'width' => 1080, 'height' => 1080],
        'instagram_post_portrait' => ['label' => 'Instagram Post (portrait)', 'width' => 1080, 'height' => 1350],
        'instagram_story' => ['label' => 'Instagram Story / Reel', 'width' => 1080, 'height' => 1920],
        'instagram_carousel' => ['label' => 'Instagram Carrousel', 'width' => 1080, 'height' => 1080],
        'facebook_post' => ['label' => 'Facebook Post', 'width' => 1200, 'height' => 630],
        'youtube_thumbnail' => ['label' => 'YouTube Miniature', 'width' => 1280, 'height' => 720],
    ];

    public const LAYOUTS = [
        'overlay' => 'Photo + titre overlay',
        'split' => 'Photo haut + titre bas',
        'bold_text' => 'Texte seul (grand)',
        'numbered' => 'Numéro + titre',
        'framed' => 'Photo encadrée + titre bandeau',
        'collage' => 'Multi-photos',
    ];

    public function getPreviewUrlAttribute(): ?string
    {
        if (! $this->preview_path) {
            return null;
        }

        return asset('storage/' . $this->preview_path);
    }

    /**
     * Get the full path to the title font TTF file.
     */
    public function getTitleFontPathAttribute(): string
    {
        return $this->getFontPath($this->title_font, $this->title_font_weight ?? 'Regular');
    }

    /**
     * Get the full path to the body font TTF file.
     */
    public function getBodyFontPathAttribute(): ?string
    {
        if (! $this->body_font) {
            return null;
        }

        return $this->getFontPath($this->body_font, $this->body_font_weight ?? 'Regular');
    }

    private function getFontPath(string $family, string $weight): string
    {
        $filename = str_replace(' ', '', $family) . '-' . $weight . '.ttf';

        return storage_path('app/fonts/' . $filename);
    }
}
