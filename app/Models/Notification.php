<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'link',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function createNotification($userId, $type, $message, $link = null)
    {
        try {
            $notification = self::create([
                'user_id' => $userId,
                'type' => $type,
                'message' => $message,
                'link' => $link,
                'is_read' => false,
            ]);

            Log::info('Notification created successfully', $notification->toArray());
            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to create notification', [
                'user_id' => $userId,
                'type' => $type,
                'message' => $message,
                'link' => $link,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Для отладки
        }
    }
}
