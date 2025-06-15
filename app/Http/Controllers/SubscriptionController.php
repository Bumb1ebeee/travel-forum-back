<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['show', 'userDiscussions']);
    }

    public function index()
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }

        $subscriptions = $user->subscriptions()
            ->get()
            ->map(function ($followedUser) {
                return [
                    'id' => $followedUser->pivot ? $followedUser->pivot->id : null,
                    'type' => 'user',
                    'user' => $followedUser->only(['id', 'name', 'username', 'email', 'avatar']),
                ];
            });

        Log::info('User subscriptions fetched', [
            'user_id' => $user->id,
            'subscriptions_count' => $subscriptions->count(),
        ]);

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function discussionSubscriptions()
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }

        $subscriptions = $user->subscribe_discussions()
            ->with(['user', 'category', 'media', 'tags'])
            ->get()
            ->map(function ($discussion) {
                return [
                    'id' => $discussion->pivot ? $discussion->pivot->id : null,
                    'type' => 'discussion',
                    'discussion' => $discussion,
                ];
            });

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function userSubscriptions()
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }

        // Загружаем подписки с пользователем
        $followedUsers = $user->subscriptions()
            ->withPivot('id') // ✅ Теперь доступно
            ->get();

        $subscriptions = $followedUsers->map(function ($followedUser) {
            return [
                'id' => $followedUser->pivot->id,
                'type' => 'user',
                'user' => [
                    'id' => $followedUser->id,
                    'name' => $followedUser->name,
                    'email' => $followedUser->email,
                    'avatar' => $followedUser->avatar ?? null,
                    'created_at' => $followedUser->created_at,
                    'updated_at' => $followedUser->updated_at,
                ],
            ];
        });

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function show($id)
    {
        $user = User::findOrFail($id);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $authUser = Auth::guard('sanctum')->user();
        $isSubscribed = $authUser
            ? Subscription::where('subscriber_id', $authUser->id)->where('user_id', $id)->exists()
            : false;

        Log::info('Show user', [
            'user_id' => $id,
            'auth_user' => $authUser ? $authUser->only(['id', 'name']) : null,
            'isSubscribed' => $isSubscribed,
        ]);

        return response()->json([
            'user' => $user->only(['id', 'name', 'username', 'email', 'avatar']),
            'isSubscribed' => $isSubscribed,
        ]);
    }

    public function userDiscussions($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $discussions = $user->discussions()
            ->with(['category:id,name', 'media'])
            ->where('is_draft', false)
            ->where('status', 'approved')
            ->get();
        return response()->json(['discussions' => $discussions]);
    }

    public function subscribe($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $authUser = Auth::guard('sanctum')->user();
        if (!$authUser) {
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }

        if ($authUser->id === $user->id) {
            return response()->json(['message' => 'Нельзя подписаться на себя'], 400);
        }

        $subscription = Subscription::firstOrCreate([
            'subscriber_id' => $authUser->id,
            'user_id' => $userId,
        ]);

        Log::info('Subscribed', [
            'subscriber_id' => $authUser->id,
            'user_id' => $userId,
            'subscription_id' => $subscription->id,
        ]);

        return response()->json(['message' => 'Подписка оформлена'], 201);
    }

    public function unsubscribe($userId)
    {
        $authUser = Auth::guard('sanctum')->user();
        if (!$authUser) {
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }

        $deleted = Subscription::where('subscriber_id', $authUser->id)
            ->where('user_id', $userId)
            ->delete();

        Log::info('Unsubscribed', [
            'subscriber_id' => $authUser->id,
            'user_id' => $userId,
            'deleted' => $deleted,
        ]);

        if (!$deleted) {
            return response()->json(['message' => 'Подписка не найдена'], 404);
        }

        return response()->json(['message' => 'Подписка отменена']);
    }
}
