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
                'query' => $request->input('query'), // Исправлено: $request->query -> $request->input('query')
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

    // Популярные обсуждения (для неавторизованных)
    public function popularDiscussions(Request $request)
    {
        $categoryId = $request->query('category_id');

        $query = Discussion::with(['user', 'category', 'media', 'tags'])->where('status', 'approved')
            ->orderBy('views', 'desc')
            ->limit(20);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $discussions = $query->get();

        return response()->json(['discussions' => $discussions]);
    }

    public function getPersonalizedDiscussions(Request $request)
    {
        $categoryId = $request->query('category_id');

        $query = Discussion::with(['user', 'category', 'media', 'tags'])->where('status', 'approved')
            ->orderBy('views', 'desc')
            ->limit(20);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $discussions = $query->get();
        return response()->json(['discussions' => $discussions]);
    }

    public function discussionsByTag($tagName)
    {
        $tag = Tag::where('name', $tagName)->firstOrFail();
        $discussions = $tag->discussions()->where('status', 'approved')->with(['user', 'media', 'tags'])->latest()->get();
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
