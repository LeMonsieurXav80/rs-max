<?php

use App\Models\YtSource;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $channels = [
            [
                'channel_id' => 'UCBKcSwUe-59z62BvE8VIGJg',
                'channel_url' => 'https://www.youtube.com/channel/UCBKcSwUe-59z62BvE8VIGJg',
                'name' => 'YouTube — UCBKcSwUe',
            ],
            [
                'channel_id' => 'UCxHshl5wN0ljpphZmv0pYNw',
                'channel_url' => 'https://www.youtube.com/channel/UCxHshl5wN0ljpphZmv0pYNw',
                'name' => 'YouTube — UCxHshl5',
            ],
        ];

        foreach ($channels as $channel) {
            YtSource::firstOrCreate(
                ['channel_id' => $channel['channel_id']],
                [
                    'name' => $channel['name'],
                    'channel_url' => $channel['channel_url'],
                    'schedule_frequency' => 'weekly',
                    'schedule_time' => '10:00',
                    'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        YtSource::whereIn('channel_id', [
            'UCBKcSwUe-59z62BvE8VIGJg',
            'UCxHshl5wN0ljpphZmv0pYNw',
        ])->delete();
    }
};
