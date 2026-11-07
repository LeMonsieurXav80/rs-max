<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountActionEvent extends Model
{
    protected static function booted(): void
    {
        static::saved(function ($model) {
            app(\App\Services\Agent\AutomationJournal::class)->event($model);
        });
    }

    protected $guarded = ['id'];
}
