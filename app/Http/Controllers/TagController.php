<?php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TagController extends Controller
{
    public function index()
    {





















        $tags = Tag::all();
        return response()->json(['tags' => $tags]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:tags,name',
        ]);

        $tag = Tag::create($validated);
        return response()->json($tag, 201);
    }

    public function attachTags(Request $request, Discussion $discussion)
    {
        $validated = $request->validate([
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'exists:tags,id',
        ]);

        $discussion->tags()->sync($validated['tag_ids']);
        return response()->json(['message' => 'Теги привязаны']);
    }

    public function popularTags(Request $request)
    {
        try {
            $categoryId = $request->query('category_id');
            $user = Auth::guard('sanctum')->user();

            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $userId = $user->id;

            $query = Tag::withCount(['discussions' => function ($q) use ($categoryId, $userId) {
                // Фильтруем обсуждения по категории, если передан category_id
                if ($categoryId) {
                    $q->where('category_id', $categoryId);
                }

                // Ограничиваем обсуждения только теми, с которыми пользователь взаимодействовал
                $q->where(function ($subQuery) use ($userId) {
                    // Реакции
                    $subQuery->whereHas('reactions', function ($reactionQuery) use ($userId) {
                        $reactionQuery->where('user_id', $userId)
                            ->where('reactable_type', 'App\\Models\\Discussion');
                    })
                        // Ответы
                        ->orWhereHas('replies', function ($replyQuery) use ($userId) {
                            $replyQuery->where('user_id', $userId);
                        })
                        // Присоединения
                        ->orWhereHas('members', function ($memberQuery) use ($userId) {
                            $memberQuery->where('user_id', $userId);
                        });
                });
            }])
                ->having('discussions_count', '>', 0) // Показываем только теги с обсуждениями
                ->orderBy('discussions_count', 'desc')
                ->limit(10);

            $tags = $query->get();

            \Log::info('Популярные теги для пользователя', [
                'user_id' => $userId,
                'category_id' => $categoryId,
                'tags_count' => $tags->count(),
                'tags' => $tags->pluck('name')->toArray(),
            ]);

            return response()->json(['tags' => $tags]);
        } catch (\Exception $e) {
            \Log::error('Ошибка получения популярных тегов', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка при получении тегов'], 500);
        }
    }
}
