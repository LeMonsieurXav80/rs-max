<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    protected $fillable = [
        'name',
        'description',
        'system_prompt',
        'tone',
        'language',
        'is_active',
        'bot_comment_context_article',
        'bot_comment_context_text',
        'bot_comment_context_image',
        'bot_comment_max_length',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'bot_comment_max_length' => 'integer',
        ];
    }

    public function rssPosts(): HasMany
    {
        return $this->hasMany(RssPost::class);
    }

    public function hasBotComments(): bool
    {
        return filled($this->bot_comment_context_text)
            || filled($this->bot_comment_context_article)
            || filled($this->bot_comment_context_image);
    }

    public function botContextFor(string $kind): ?string
    {
        return match ($kind) {
            'article' => $this->bot_comment_context_article,
            'image' => $this->bot_comment_context_image,
            default => $this->bot_comment_context_text,
        } ?: null;
    }
}
