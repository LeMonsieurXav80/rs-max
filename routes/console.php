<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Publish scheduled posts every minute
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();
