<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
            $healthy = false;
        }

        // Queue worker (heartbeat set by queue worker via cache)
        $queueHeartbeat = Cache::get('health:queue_worker');
        $checks['queue_worker'] = $queueHeartbeat ? 'ok' : 'no heartbeat';
        if (! $queueHeartbeat) {
            $healthy = false;
        }

        // Scheduler (heartbeat set by scheduled task)
        $schedulerHeartbeat = Cache::get('health:scheduler');
        $checks['scheduler'] = $schedulerHeartbeat ? 'ok' : 'no heartbeat';
        if (! $schedulerHeartbeat) {
            $healthy = false;
        }

        // Disk space
        $freeBytes = disk_free_space(storage_path());
        $freeMB = $freeBytes ? round($freeBytes / 1024 / 1024) : 0;
        $checks['disk_free_mb'] = $freeMB;
        if ($freeMB < 100) {
            $checks['disk'] = 'low';
            $healthy = false;
        } else {
            $checks['disk'] = 'ok';
        }

        return response()->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
