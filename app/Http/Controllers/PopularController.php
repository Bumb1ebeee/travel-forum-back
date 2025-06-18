<?php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\SearchQuery;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PopularController extends Controller
{
    public function autocomplete(Request $request)
    {
        $query = $request->query('query');
        if (!$query) {
            return response()->json(['suggestions' => []], 200);
        }

        $tags = Tag::where('name', 'like', "%{$query}%")
            ->take(5)
            ->pluck('name');

        return response()->json(['suggestions' => $tags], 200);
    }

    public function saveSearchQuery(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:255',
        ]);

        $user = $request->user();
        if ($user) {
            SearchQuery::create([
                'user_id' => $user->id,
                'query' => $request->input('query'),
            ]);
        }

        return response()->json(['message' => 'Поисковый запрос сохранён'], 200);
    }

    public function search(Request $request)
    {
        $query = $request->query('query');
        \Log::info('Search request', ['query' => $query]);

        if (!$query) {
            return response()->json(['discussions' => []], 200);
        }

        $discussions = Discussion::where('title', 'like', "%{$query}%")
            ->orWhere('description', 'like', "%{$query}%")
            ->orWhereHas('tags', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })->where('status', 'approved')
            ->with(['user', 'media', 'tags'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json(['discussions' => $discussions], 200);
    }

    public function popularDiscussions(Request $request)
    {
        $categoryId = $request->query('category_id');
        $page = $request->query('page', 1);
        $perPage = 10;
        $sortBy = $request->query('sort_by', 'most_viewed'); // По умолчанию most_viewed
        $user = $request->user();

        \Log::info('Popular discussions request', [
            'category_id' => $categoryId,
            'page' => $page,
            'sort_by' => $sortBy,
        ]);

        // Базовый запрос
        $query = Discussion::with(['user', 'category', 'media', 'tags'])
            ->where('status', 'approved');

        // Сортировка
        switch ($sortBy) {
            case 'newest':
                $query->orderByDesc('published_at');
                break;
            case 'popular':
                $query->orderByDesc('views');
                break;
            case 'most_viewed':
                $query->orderByDesc('views');
                break;
            default:
                $query->orderByDesc('views'); // Дефолтная сортировка
                \Log::warning('Invalid sort_by parameter', ['sort_by' => $sortBy]);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $discussions = $query->paginate($perPage, ['*'], 'page', $page);

        // Получаем популярные теги
        $tags = Tag::withCount('discussions')
            ->orderByDesc('discussions_count')
            ->take(2)
            ->get(['id', 'name', 'discussions_count']);

        return response()->json([
            'discussions' => $discussions->items(),
            'pagination' => [
                'current_page' => $discussions->currentPage(),
                'last_page' => $discussions->lastPage(),
                'total' => $discussions->total(),
                'per_page' => $discussions->perPage(),
            ],
            'tags' => $tags,
        ], 200);
    }

    public function getPersonalizedDiscussions(Request $request)
    {
        $categoryId = $request->query('category_id');
        $page = $request->query('page', 1);
        $perPage = 10;
        $sortBy = $request->query('sort_by', 'most_viewed');
        $user = $request->user();

        \Log::info('Personalized discussions request', [
            'category_id' => $categoryId,
            'page' => $page,
            'sort_by' => $sortBy,
            'user_id' => $user?->id,
        ]);

        $query = Discussion::with(['user', 'category', 'media', 'tags'])
            ->where('status', 'approved');

        if ($user) {
            $viewedTags = \DB::table('views')
                ->join('discussion_tag', 'views.discussion_id', '=', 'discussion_tag.discussion_id')
                ->join('tags', 'discussion_tag.tag_id', '=', 'tags.id')
                ->where('views.user_id', $user->id)
                ->pluck('tags.id')
                ->unique();

            $query->whereHas('tags', function ($q) use ($viewedTags) {
                $q->whereIn('tags.id', $viewedTags);
            })->orWhereDoesntHave('tags');
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        // Сортировка
        switch ($sortBy) {
            case 'newest':
                $query->orderByDesc('published_at');
                break;
            case 'popular':
                $query->orderByDesc('views');
                break;
            case 'most_viewed':
                $query->orderByDesc('views');
                break;
            default:
                $query->orderByDesc('views');
                \Log::warning('Invalid sort_by parameter', ['sort_by' => $sortBy]);
        }

        $discussions = $query->paginate($perPage, ['*'], 'page', $page);

        $tags = Tag::withCount('discussions')
            ->orderByDesc('discussions_count')
            ->take(2)
            ->get(['id', 'name', 'discussions_count']);

        return response()->json([
            'discussions' => $discussions->items(),
            'pagination' => [
                'current_page' => $discussions->currentPage(),
                'last_page' => $discussions->lastPage(),
                'total' => $discussions->total(),
                'per_page' => $discussions->perPage(),
            ],
            'tags' => $tags,
        ], 200);
    }

    public function discussionsByTag($tagName)
    {
        $tag = Tag::where('name', $tagName)->firstOrFail();
        $discussions = $tag->discussions()->where('status', 'approved')
            ->with(['user', 'category', 'media', 'tags'])
            ->latest()
            ->get();
        return response()->json(['discussions' => $discussions], 200);
    }

    public function joinDiscussion($id)
    {
        $this->middleware('auth:api');

        $discussion = Discussion::findOrFail($id)->where('status', 'approved');
        $discussion->members()->syncWithoutDetaching([Auth::id()]);

        return response()->json(['success' => true]);
    }
}
