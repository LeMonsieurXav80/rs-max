<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gabarit de slide de carrousel stocké en base (« template » dans l'interface).
 *
 * Le champ `html` est un gabarit à marqueurs, rendu sans exécution par
 * App\Services\Carousel\TemplateRenderer. Voir App\Services\Carousel\BrickRegistry
 * qui fusionne ces briques avec celles déclarées dans config/carousel.php.
 */
class CarouselBrick extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'ratios',
        'slots',
        'html',
        'css',
        'sample_data',
        'user_id',
        'sort_order',
    ];

    protected $casts = [
        'ratios' => 'array',
        'slots' => 'array',
        'sample_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
