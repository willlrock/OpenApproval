<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Models\Tracker\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Notification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'type', 'data', 'read_at', 'created_at']);

        return response()->json([
            'unread' => $items->whereNull('read_at')->count(),
            'items' => $items->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $query = Notification::where('user_id', $request->user()->id)->whereNull('read_at');

        if ($request->filled('id')) {
            $query->where('id', $request->integer('id'));
        }

        $query->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
