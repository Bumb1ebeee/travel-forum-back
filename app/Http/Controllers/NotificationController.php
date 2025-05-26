<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                \Log::warning('Unauthorized attempt to fetch notifications', [
                    'token' => $request->bearerToken() ?? 'none',
                ]);
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $notifications = Notification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            \Log::info('Fetching notifications', [
                'user_id' => $user->id,
                'count' => $notifications->count(),
                'notifications' => $notifications->toArray(),
            ]);

            return response()->json([
                'notifications' => $notifications,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching notifications', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Error fetching notifications'], 500);
        }
    }

    public function markAsRead($id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $notification->update(['is_read' => true]);

            \Log::info('Notification marked as read', [
                'user_id' => $user->id,
                'notification_id' => $id,
            ]);

            return response()->json(['message' => 'Notification marked as read']);
        } catch (\Exception $e) {
            \Log::error('Error marking notification as read', [
                'user_id' => $user->id ?? 'unknown',
                'notification_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Error marking notification'], 500);
        }
    }
}
