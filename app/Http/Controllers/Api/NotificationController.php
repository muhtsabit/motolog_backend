<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // GET /api/notifications/{userId}
    public function index($userId)
    {
        $notifications = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications, 200);
    }

    // POST /api/notifications/read/{id}
    public function markAsRead($id)
    {
        DB::table('notifications')
            ->where('id', $id)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return response()->json(['status' => 'success'], 200);
    }

    // POST /api/notifications/read-all/{userId}
    public function markAllAsRead($userId)
    {
        DB::table('notifications')
            ->where('user_id', $userId)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return response()->json(['status' => 'success'], 200);
    }
}