<?php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\Reaction;
use App\Models\Reply;
use App\Models\User;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StatisticsController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if ($user->role === 'admin') {
            return response()->json([
                'totalUsers' => User::count(),
                'publishedDiscussions' => Discussion::where('status', 'approved')->count(), // Исправлено на approved
                'totalReplies' => Reply::count(),
                'totalLikes' => Reaction::where('reaction', 'like')->count(),
                'activeUsers' => User::whereHas('discussions', function ($query) {
                    $query->where('created_at', '>=', now()->subDays(30));
                })->orWhereHas('replies', function ($query) {
                    $query->where('created_at', '>=', now()->subDays(30));
                })->count(),
            ]);
        } elseif ($user->role === 'moderator') {
            return response()->json([
                'pendingReports' => Report::where('status', 'pending')->count(),
                'pendingDiscussions' => Discussion::where('status', 'pending')->where('is_draft', false)->count(),
                'reviewedDiscussions' => Discussion::where('moderator_id', $user->id)
                    ->whereIn('status', ['approved', 'rejected'])
                    ->count(),
                'reviewedReplies' => Reply::where('moderator_id', $user->id)->count(),
                'processedReports' => Report::where('moderator_id', $user->id)
                    ->whereIn('status', ['approved', 'rejected'])
                    ->where('updated_at', '>=', now()->subDays(30))
                    ->count(),
            ]);
        } else {
            // Пользователь
            return response()->json([
                'totalDiscussions' => Discussion::where('user_id', $user->id)->count(),
                'totalReplies' => Reply::where('user_id', $user->id)->count(),
                'totalLikes' => Reaction::where('reaction', 'like')
                    ->where(function ($query) use ($user) {
                        $query->whereHasMorph(
                            'reactable',
                            [Discussion::class, Reply::class],
                            function ($query, $type) use ($user) {
                                $query->where('user_id', $user->id);
                            }
                        );
                    })->count(),
                'subscriptionsMade' => $user->subscriptions()->count(),
                'subscribers' => $user->subscribers()->count(),
                'totalViews' => Discussion::where('user_id', $user->id)->sum('views'),
            ]);
        }
    }

    public function likes(Request $request)
    {
        $user = Auth::user();

        // Ограничиваем доступ только для admin и user
        if ($user->role === 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $days = 30;
        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = $date;

            $query = Reaction::where('reaction', 'like')
                ->whereDate('created_at', $date);

            if ($user->role === 'user') {
                $query->where(function ($query) use ($user) {
                    $query->whereHasMorph(
                        'reactable',
                        [Discussion::class, Reply::class],
                        function ($query, $type) use ($user) {
                            $query->where('user_id', $user->id);
                        }
                    );
                });
            }

            $values[] = $query->count();
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    public function users(Request $request)
    {
        $days = 30;
        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = $date;
            $values[] = User::whereDate('created_at', $date)->count();
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    public function replies(Request $request)
    {
        $days = 30;
        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = $date;
            $values[] = Reply::whereDate('created_at', $date)->count();
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    public function reports(Request $request)
    {
        $user = Auth::user();
        $days = 30;
        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = $date;

            $query = Report::whereDate('updated_at', $date);

            if ($user->role === 'moderator') {
                $query->where('moderator_id', $user->id)
                    ->whereIn('status', ['approved', 'rejected']);
            } else {
                $query->whereIn('status', ['approved', 'rejected']);
            }

            $values[] = $query->count();
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    public function views(Request $request)
    {
        $user = Auth::user();
        $days = 30;
        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = $date;

            $values[] = Discussion::where('user_id', $user->id)
                ->whereDate('created_at', $date)
                ->sum('views');
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
        ]);
    }

    public function recentActions(Request $request)
    {
        $user = Auth::user();
        $limit = 5;

        if ($user->role === 'moderator') {
            $discussions = Discussion::where('moderator_id', $user->id)
                ->whereIn('status', ['approved', 'rejected'])
                ->orderBy('updated_at', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'status', 'updated_at']);

            $replies = Reply::where('moderator_id', $user->id)
                ->orderBy('updated_at', 'desc')
                ->take($limit)
                ->get(['id', 'content', 'updated_at']);

            $reports = Report::where('moderator_id', $user->id)
                ->whereIn('status', ['approved', 'rejected'])
                ->with(['moderator:id,username']) // Загружаем модератора
                ->orderBy('updated_at', 'desc')
                ->take($limit)
                ->get(['id', 'status', 'updated_at', 'moderator_id']);

            return response()->json([
                'recentDiscussions' => $discussions,
                'recentReplies' => $replies,
                'recentReports' => $reports,
            ]);
        }

        return response()->json([]);
    }
}
