<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ReadinessController extends Controller
{
    public function __invoke()
    {
        try {
            $fresh = ! in_array(config('queue.default'), ['sync', 'null'], true) && DB::table('operational_heartbeats')
                ->whereIn('component', ['scheduler', 'queue'])->where('last_seen_at', '>=', now()->subMinutes(5))->count() === 2;
        } catch (\Throwable) {
            $fresh = false;
        }

        $revision = base_path('.release-sha');
        $body = ['status' => $fresh ? 'ok' : 'unavailable'];
        if (is_file($revision)) {
            $body['release'] = trim(file_get_contents($revision));
        }

        return response()->json($body, $fresh ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
