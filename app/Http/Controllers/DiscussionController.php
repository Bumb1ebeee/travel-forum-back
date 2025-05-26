<?php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\DiscussionView;
use App\Models\Notification;
use App\Models\Reaction;
use App\Models\Reply;
use App\Models\Media;
use App\Models\MediaContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ImageKit\ImageKit;

class DiscussionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $query = Discussion::where('user_id', $user->id)
                ->where('is_draft', false)
                ->where('status', 'approved')
                ->with(['user', 'category', 'media'])
                ->latest();

            if ($categoryId = $request->query('category_id')) {
                $query->where('category_id', $categoryId);
            }

            $discussions = $query->get();
            Log::info('Discussions fetched', ['user_id' => $user->id, 'count' => $discussions->count()]);
            return response()->json(['discussions' => $discussions]);
        } catch (\Exception $e) {
            Log::error('Error fetching discussions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error fetching discussions'], 500);
        }
    }

    public function getUserDrafts()
    {
        try {
            if (!Auth::check()) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $drafts = Discussion::where('user_id', Auth::id())
                ->where('is_draft', true)
                ->with([
                    'category',
                    'tags',
                    'media.content' => function ($query) {
                        $query->select('id', 'media_id', 'file_id', 'content_type', 'order', 'image_url', 'video_url', 'music_url', 'text_content', 'map_points');
                    }
                ])
                ->get();

            // Форматируем медиа для совместимости
            $formattedDrafts = $drafts->map(function ($draft) {
                $formattedMedia = $draft->media->map(function ($item) {
                    $content = $item->content;
                    Log::debug('Форматирование медиа в getUserDrafts', [
                        'media_id' => $item->id,
                        'content_exists' => !empty($content),
                        'content_type' => $content ? $content->content_type : $item->type,
                    ]);

                    if (!$content) {
                        Log::warning('Связь content отсутствует для медиа', [
                            'media_id' => $item->id,
                            'type' => $item->type,
                        ]);
                    }

                    return [
                        'id' => $item->id,
                        'file_type' => $item->file_type,
                        'type' => $item->type,
                        'content_type' => $content ? $content->content_type : $item->type,
                        'content' => $content ? [
                            'file_id' => $content->file_id ?? $item->id,
                            'content_type' => $content->content_type,
                            'order' => $content->order,
                            'image_url' => $content->image_url,
                            'video_url' => $content->video_url,
                            'music_url' => $content->music_url,
                            'text_content' => $content->text_content,
                            'map_points' => $content->map_points,
                        ] : [
                            'file_id' => $item->id,
                            'content_type' => $item->type,
                            'order' => 0,
                            'image_url' => $item->type === 'image' ? 'https://placehold.co/80x80' : null,
                            'video_url' => null,
                            'music_url' => null,
                            'text_content' => $item->type === 'text' ? '' : null,
                            'map_points' => $item->type === 'map' ? [] : null,
                        ],
                    ];
                });

                $draftArray = $draft->toArray();
                $draftArray['media'] = $formattedMedia;

                return $draftArray;
            });

            Log::info('Черновики успешно получены', [
                'user_id' => Auth::id(),
                'draft_count' => $drafts->count(),
                'media_count' => $drafts->sum(fn($draft) => $draft->media->count()),
            ]);

            return response()->json(['discussions' => $formattedDrafts], 200);
        } catch (\Exception $e) {
            Log::error('Ошибка получения черновиков', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка получения черновиков'], 500);
        }
    }

    public function getUserPublished()
    {
        try {
            $published = Discussion::where('user_id', Auth::id())
                ->where('status', 'approved')->where('is_draft', false)
                ->with(['category', 'tags'])
                ->get();
            return response()->json(['discussions' => $published]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения опубликованных обсуждений', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка получения опубликованных обсуждений'], 500);
        }
    }

    public function getUserPending()
    {
        try {
            $pending = Discussion::where('user_id', Auth::id())
                ->where('status', 'pending')->where('is_draft', false)
                ->with(['category', 'tags'])
                ->get();
            return response()->json(['discussions' => $pending]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения ожидающих обсуждений', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка получения ожидающих обсуждений'], 500);
        }
    }

    public function getUserRejected()
    {
        try {
            $rejected = Discussion::where('user_id', Auth::id())
                ->where('status', 'rejected')->where('is_draft', false)
                ->with(['category', 'tags'])
                ->get();
            return response()->json(['discussions' => $rejected]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения отклонённых обсуждений', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка получения отклонённых обсуждений'], 500);
        }
    }

    public function saveDraft(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'description' => 'nullable|string',
                'map' => 'nullable|array|max:10',
                'map.*.lat' => 'nullable|numeric|between:-90,90',
                'map.*.lng' => 'nullable|numeric|between:-180,180',
                'map_start' => 'nullable|array',
                'map_start.lat' => 'nullable|numeric|between:-90,90',
                'map_start.lng' => 'nullable|numeric|between:-180,180',
                'map_end' => 'nullable|array',
                'map_end.lat' => 'nullable|numeric|between:-90,90',
                'map_end.lng' => 'nullable|numeric|between:-180,180',
                'status' => 'nullable|string|in:pending,approved,rejected',
            ]);

            // Проверка на существующий черновик
            $existingDraft = Discussion::where('user_id', $user->id)
                ->where('title', $validated['title'])
                ->where('is_draft', true)
                ->first();

            if ($existingDraft) {
                Log::info('Обнаружен существующий черновик', ['draft_id' => $existingDraft->id]);
                return response()->json(['message' => 'Черновик уже существует', 'draft' => $existingDraft], 200);
            }

            $draft = Discussion::create([
                'user_id' => $user->id,
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'description' => $validated['description'] ?? '',
                'map' => $validated['map'] ?? [],
                'map_start' => $validated['map_start'] ?? null,
                'map_end' => $validated['map_end'] ?? null,
                'is_draft' => true,
                'status' => $validated['status'] ?? 'pending',
                'views' => 0,
            ]);

            Log::info('Draft created', ['user_id' => $user->id, 'draft_id' => $draft->id]);
            return response()->json(['message' => 'Черновик создан', 'draft' => $draft], 201);
        } catch (\Exception $e) {
            Log::error('Error saving draft', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка сохранения черновика'], 500);
        }
    }

    public function updateDraft(Request $request, $id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $draft = Discussion::where('user_id', $user->id)
                ->where('is_draft', true)
                ->findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'description' => 'nullable|string',
                'map' => 'nullable|array|max:10',
                'map.*.lat' => 'nullable|numeric|between:-90,90',
                'map.*.lng' => 'nullable|numeric|between:-180,180',
                'map_start' => 'nullable|array',
                'map_start.lat' => 'nullable|numeric|between:-90,90',
                'map_start.lng' => 'nullable|numeric|between:-180,180',
                'map_end' => 'nullable|array',
                'map_end.lat' => 'nullable|numeric|between:-90,90',
                'map_end.lng' => 'nullable|numeric|between:-180,180',
                'status' => 'nullable|string|in:pending,approved,rejected',
            ]);

            // Extract media IDs from the description
            $currentMediaIds = [];
            if ($draft->description) {
                preg_match_all('/data-media-id="([^"]+)"/', $draft->description, $matches);
                $currentMediaIds = $matches[1] ?? [];
            }

            $newMediaIds = [];
            if ($validated['description']) {
                preg_match_all('/data-media-id="([^"]+)"/', $validated['description'], $matches);
                $newMediaIds = $matches[1] ?? [];
            }

            // Delete media that are no longer referenced
            $mediaIdsToDelete = array_diff($currentMediaIds, $newMediaIds);
            if (!empty($mediaIdsToDelete)) {
                foreach ($mediaIdsToDelete as $mediaId) {
                    try {
                        $media = Media::find($mediaId);
                        if ($media) {
                            $mediaContent = MediaContent::where('media_id', $mediaId)->first();
                            if ($mediaContent && $mediaContent->{$media->type . '_url'}) {
                                $imageKit = new ImageKit(
                                    env('IMAGEKIT_PUBLIC_KEY'),
                                    env('IMAGEKIT_PRIVATE_KEY'),
                                    env('IMAGEKIT_URL_ENDPOINT')
                                );
                                $fileId = basename(parse_url($mediaContent->{$media->type . '_url'}, PHP_URL_PATH));
                                $imageKit->deleteFile($fileId);
                                Log::info('Media deleted from ImageKit', ['file_id' => $fileId]);
                            }
                            $media->delete();
                            Log::info('Media deleted from database', ['media_id' => $mediaId]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete media', ['media_id' => $media, 'error' => $e->getMessage()]);
                    }
                }
            }

            $updateData = [
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'description' => $validated['description'] ?? '',
                'map' => $validated['map'] ?? [],
                'map_start' => $validated['map_start'] ?? $draft->map_start,
                'map_end' => $validated['map_end'] ?? $draft->map_end,
                'status' => $validated['status'] ?? $draft->status,
                'is_draft' => true,
            ];

            $draft->update($updateData);
            $draft->load('category', 'media');

            Log::info('Draft updated', ['id' => $id, 'user_id' => $user->id]);
            return response()->json(['message' => 'Черновик обновлен', 'draft' => $draft]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error updating draft', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Draft not found', ['id' => $id, 'user_id' => $user->id]);
            return response()->json(['message' => 'Черновик не найден'], 404);
        } catch (\Exception $e) {
            Log::error('Error updating draft', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка при обновлении черновика'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $discussion = Discussion::where('user_id', $user->id)->findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'description' => 'nullable|string',
                'map' => 'nullable|array|max:10',
                'map.*.lat' => 'nullable|numeric|between:-90,90',
                'map.*.lng' => 'nullable|numeric|between:-180,180',
                'map_start' => 'nullable|array',
                'map_start.lat' => 'nullable|numeric|between:-90,90',
                'map_start.lng' => 'nullable|numeric|between:-180,180',
                'map_end' => 'nullable|array',
                'map_end.lat' => 'nullable|numeric|between:-90,90',
                'map_end.lng' => 'nullable|numeric|between:-180,180',
                'status' => 'nullable|string|in:pending,approved,rejected',
                'publish' => 'sometimes|boolean',
            ]);

            // Extract media IDs from the description
            $currentMediaIds = [];
            if ($discussion->description) {
                preg_match_all('/data-media-id="([^"]+)"/', $discussion->description, $matches);
                $currentMediaIds = $matches[1] ?? [];
            }

            $newMediaIds = [];
            if ($validated['description']) {
                preg_match_all('/data-media-id="([^"]+)"/', $validated['description'], $matches);
                $newMediaIds = $matches[1] ?? [];
            }

            // Delete media that are no longer referenced
            $mediaIdsToDelete = array_diff($currentMediaIds, $newMediaIds);
            if (!empty($mediaIdsToDelete)) {
                foreach ($mediaIdsToDelete as $mediaId) {
                    try {
                        $media = Media::find($mediaId);
                        if ($media) {
                            $mediaContent = MediaContent::where('media_id', $mediaId)->first();
                            if ($mediaContent && $mediaContent->{$media->type . '_url'}) {
                                $imageKit = new ImageKit(
                                    env('IMAGEKIT_PUBLIC_KEY'),
                                    env('IMAGEKIT_PRIVATE_KEY'),
                                    env('IMAGEKIT_URL_ENDPOINT')
                                );
                                $fileId = basename(parse_url($mediaContent->{$media->type . '_url'}, PHP_URL_PATH));
                                $imageKit->deleteFile($fileId);
                                Log::info('Media deleted from ImageKit', ['file_id' => $fileId]);
                            }
                            $media->delete();
                            Log::info('Media deleted from database', ['media_id' => $mediaId]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete media', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
                    }
                }
            }

            $updateData = [
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'description' => $validated['description'] ?? '',
                'map' => $validated['map'] ?? [],
                'map_start' => $validated['map_start'] ?? $discussion->map_start,
                'map_end' => $validated['map_end'] ?? $discussion->map_end,
                'status' => $validated['status'] ?? $discussion->status,
            ];

            if ($request->has('publish') && $validated['publish']) {
                $updateData['is_draft'] = false;
                $updateData['published_at'] = now();
                $updateData['status'] = 'pending';
            }

            $discussion->update($updateData);
            $discussion->load('category', 'media');

            Log::info('Discussion updated', ['id' => $id, 'publish' => $request->input('publish', false)]);
            return response()->json(['message' => 'Обсуждение обновлено', 'draft' => $discussion]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error updating discussion', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating discussion', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка при обновлении обсуждения'], 500);
        }
    }

    public function show($id)
    {
        try {
            $discussion = Discussion::with([
                'user',
                'category',
                'media.content',
                'reactions'
            ])
                ->where('status', 'approved')
                ->findOrFail($id);
            $discussion->increment('views');

            $replies = Reply::with([
                'user',
                'reactions',
                'media.content', // Обязательно
                'children' => function ($query) {
                    $this->loadNestedChildren($query);
                }
            ])
                ->where('discussion_id', $id)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'asc')
                ->get();

            $user = Auth::guard('sanctum')->user();
            $userId = $user ? $user->id : null;

            // Записываем просмотр, если пользователь авторизован
            if ($userId) {
                DiscussionView::firstOrCreate([
                    'user_id' => $userId,
                    'discussion_id' => $id,
                ]);
            }

            $replies->each(function ($reply) use ($userId) {
                $reply->likes = $reply->reactions->where('reaction', 'like')->count() - $reply->reactions->where('reaction', 'dislike')->count();
                $reply->userReaction = $userId ? $reply->reactions->where('user_id', $userId)->first()?->reaction : null;
                unset($reply->reactions);
                $this->calculateLikesForChildren($reply->children, $userId);
            });

            $isJoined = $user ? $discussion->members()->where('user_id', $user->id)->exists() : false;
            Log::info('Обсуждение просмотрено', ['discussion_id' => $id, 'user_id' => $userId ?? 'гость', 'isJoined' => $isJoined]);

            return response()->json([
                'discussion' => $discussion,
                'replies' => $replies,
                'isJoined' => $isJoined,
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка загрузки обсуждения', ['discussion_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка при загрузке обсуждения'], 500);
        }
    }

    private function loadNestedChildren($query)
    {
        $query->with([
            'user',
            'reactions',
            'parent.user',
            'media.content', // Обязательно для вложенных ответов
            'children' => function ($childQuery) {
                $this->loadNestedChildren($childQuery);
            }
        ]);
    }

    private function calculateLikesForChildren($children, $userId)
    {
        $children->each(function ($reply) use ($userId) {
            $reply->likes = $reply->reactions->where('reaction', 'like')->count() - $reply->reactions->where('reaction', 'dislike')->count();
            $reply->userReaction = $userId ? $reply->reactions->where('user_id', $userId)->first()?->reaction : null;
            unset($reply->reactions);
            if ($reply->children->isNotEmpty()) {
                $this->calculateLikesForChildren($reply->children, $userId);
            }
        });
    }

    public function uploadImage(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,mp4,mov,mp3,wav|max:102400',
                'file_type' => 'required|string|in:image/jpeg,image/png,image/jpg,image/gif,image/webp,video/mp4,video/mov,audio/mp3,audio/wav',
                'mediable_id' => 'required|integer',
                'mediable_type' => 'required|string|in:App\\Models\\Discussion',
            ]);

            $type = str_starts_with($request->input('file_type'), 'image/') ? 'image' :
                (str_starts_with($request->input('file_type'), 'video/') ? 'video' : 'music');

            $media = new Media();
            $media->type = $type;
            $media->mediable_id = $validated['mediable_id'];
            $media->mediable_type = $validated['mediable_type'];
            $media->file_type = $validated['file_type'];
            $media->save();

            $mediaContent = new MediaContent();
            $mediaContent->media_id = $media->id;
            $mediaContent->file_id = $media->id;
            $mediaContent->order = 0;

            $file = $request->file('file');
            if (!$file->isValid()) {
                $media->delete();
                Log::error('Invalid file uploaded', ['file' => $file->getClientOriginalName()]);
                return response()->json(['error' => 'Invalid file'], 400);
            }

            $fileName = uniqid() . '_' . $file->getClientOriginalName();
            $path = 'discussions/' . $type . '/' . $media->id . '_' . $fileName;

            $imageKit = new ImageKit(
                env('IMAGEKIT_PUBLIC_KEY'),
                env('IMAGEKIT_PRIVATE_KEY'),
                env('IMAGEKIT_URL_ENDPOINT')
            );

            $uploadResult = $imageKit->uploadFile([
                'file' => base64_encode(file_get_contents($file->getPathname())),
                'fileName' => $fileName,
                'folder' => '/discussions/' . $type,
                'useUniqueFileName' => true,
            ]);

            if (!$uploadResult || !isset($uploadResult->result) || empty($uploadResult->result->url)) {
                $media->delete();
                Log::error('ImageKit upload failed: invalid response', [
                    'response' => (array)$uploadResult,
                    'fileName' => $fileName,
                ]);
                return response()->json(['error' => 'Failed to upload file to ImageKit'], 500);
            }

            $urlColumn = $type . '_url';
            $mediaContent->$urlColumn = $uploadResult->result->url;
            $mediaContent->content_type = $file->getMimeType();
            $mediaContent->save();

            Log::info('File uploaded and saved to database', ['media_id' => $media->id, 'url' => $uploadResult->result->url]);
            return response()->json(['url' => $uploadResult->result->url, 'mediaId' => $media->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error uploading image', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error uploading image', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error uploading image'], 500);
        }
    }

    public function join($id)
    {
        try {
            $discussion = Discussion::where('status', 'approved')->findOrFail($id);
            $userId = Auth::id();
            $discussion->members()->syncWithoutDetaching([$userId => ['created_at' => now(), 'updated_at' => now()]]);
            return response()->json(['success' => true, 'isJoined' => true]);
        } catch (\Exception $e) {
            Log::error('Error joining discussion', ['discussion_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error joining discussion'], 500);
        }
    }

    public function archiveSubscription(Request $request, $id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            if (!$user->subscribe_discussions()->where('discussion_id', $id)->exists()) {
                return response()->json(['message' => 'Вы не подписаны на это обсуждение'], 400);
            }

            $user->archivedDiscussions()->syncWithoutDetaching([$id]);
            $user->subscribe_discussions()->detach($id);
            Log::info('Discussion archived', ['user_id' => $user->id, 'discussion_id' => $id]);
            return response()->json(['message' => 'Обсуждение добавлено в архив']);
        } catch (\Exception $e) {
            Log::error('Error archiving discussion', ['discussion_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error archiving discussion'], 500);
        }
    }

    public function unarchiveSubscription(Request $request, $discussionId)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $subscription = DB::table('user_discussion_archives')
                ->where('discussion_id', $discussionId)
                ->where('user_id', $user->id)
                ->first();

            if (!$subscription) {
                return response()->json(['message' => 'Архивированная подписка не найдена'], 404);
            }

            DB::table('discussion_members')->insert([
                'user_id' => $subscription->user_id,
                'discussion_id' => $subscription->discussion_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_discussion_archives')
                ->where('discussion_id', $discussionId)
                ->where('user_id', $user->id)
                ->delete();

            Log::info('Discussion unarchived', ['user_id' => $user->id, 'discussion_id' => $discussionId]);
            return response()->json(['message' => 'Подписка разархивирована']);
        } catch (\Exception $e) {
            Log::error('Error unarchiving discussion', ['discussion_id' => $discussionId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error unarchiving discussion'], 500);
        }
    }

    public function checkSubscription(Request $request, $discussionId)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $subscription = DB::table('discussion_members')
                ->where('discussion_id', $discussionId)
                ->where('user_id', $user->id)
                ->first();

            $isArchived = DB::table('user_discussion_archives')
                ->where('discussion_id', $discussionId)
                ->where('user_id', $user->id)
                ->exists();

            return response()->json([
                'subscribed' => !!$subscription || $isArchived,
                'is_archived' => $isArchived,
                'subscription_id' => $subscription ? $subscription->id : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking subscription', ['discussion_id' => $discussionId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error checking subscription'], 500);
        }
    }

    public function unsubscribe(Request $request, $discussionId)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $deleted = DB::table('discussion_members')
                ->where('discussion_id', $discussionId)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted) {
                Log::info('Subscription removed', ['user_id' => $user->id, 'discussion_id' => $discussionId]);
                return response()->json(['message' => 'Подписка удалена']);
            }

            return response()->json(['message' => 'Подписка не найдена'], 404);
        } catch (\Exception $e) {
            Log::error('Error unsubscribing', ['discussion_id' => $discussionId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error unsubscribing'], 500);
        }
    }

    public function unsubscribeArchived(Request $request, $discussionId)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $deleted = DB::table('user_discussion_archives')
                ->where('discussion_id', $discussionId)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted) {
                Log::info('Archived subscription removed', ['user_id' => $user->id, 'discussion_id' => $discussionId]);
                return response()->json(['message' => 'Архивированная подписка удалена']);
            }

            return response()->json(['message' => 'Архивированная подписка не найдена'], 404);
        } catch (\Exception $e) {
            Log::error('Error removing archived subscription', ['discussion_id' => $discussionId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error removing archived subscription'], 500);
        }
    }

    public function archived()
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $discussions = $user->archivedDiscussions()->with(['category', 'user'])->get();
            Log::info('Archived discussions fetched', ['user_id' => $user->id, 'count' => $discussions->count()]);
            return response()->json($discussions);
        } catch (\Exception $e) {
            Log::error('Error fetching archived discussions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error fetching archived discussions'], 500);
        }
    }

    public function pendingDiscussions(Request $request)
    {
        try {
            if ($request->user()->role !== 'moderator') {
                return response()->json(['message' => 'Доступ запрещен'], 403);
            }

            $discussions = Discussion::where('status', 'pending')->where('is_draft', false)
                ->with(['user', 'media', 'category', 'media.content',])
                ->latest()
                ->get();

            Log::info('Pending discussions fetched', ['user_id' => $request->user()->id, 'count' => $discussions->count()]);
            return response()->json(['discussions' => $discussions]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending discussions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error fetching pending discussions'], 500);
        }
    }

    public function moderate(Request $request, $id)
    {
        try {
            if ($request->user()->role !== 'moderator') {
                return response()->json(['message' => 'Доступ запрещен'], 403);
            }

            $request->validate([
                'status' => 'required|in:approved,rejected',
                'moderator_comment' => 'nullable|string',
            ]);

            $discussion = Discussion::findOrFail($id);
            $discussion->update([
                'status' => $request->status,
                'moderator_comment' => $request->moderator_comment,
                'moderator_id' => $request->user()->id, // Добавляем moderator_id
            ]);

            Notification::createNotification(
                $discussion->user_id,
                'discussion_status',
                "Статус вашего обсуждения '{$discussion->title}' изменен на '{$request->status}'.",
                "/discussions/{$discussion->id}"
            );

            Log::info('Discussion moderated', ['discussion_id' => $id, 'status' => $request->status]);
            return response()->json(['discussion' => $discussion]);
        } catch (\Exception $e) {
            Log::error('Error moderating discussion', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка при модерации'], 500);
        }
    }

    public function deleteDiscussion($id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $discussion = Discussion::where('id', $id)->where('user_id', $user->id)->first();
            if (!$discussion) {
                return response()->json(['message' => 'Discussion not found'], 404);
            }

            $mediaItems = Media::where('mediable_id', $id)
                ->where('mediable_type', 'App\\Models\\Discussion')
                ->get();

            $imageKit = new ImageKit(
                env('IMAGEKIT_PUBLIC_KEY'),
                env('IMAGEKIT_PRIVATE_KEY'),
                env('IMAGEKIT_URL_ENDPOINT')
            );

            foreach ($mediaItems as $media) {
                $mediaContent = MediaContent::where('media_id', $media->id)->first();
                if ($mediaContent && $mediaContent->{$media->type . '_url'}) {
                    $fileId = basename(parse_url($mediaContent->{$media->type . '_url'}, PHP_URL_PATH));
                    try {
                        $imageKit->deleteFile($fileId);
                        Log::info('Media deleted from ImageKit', ['file_id' => $fileId]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete media from ImageKit', ['file_id' => $fileId, 'error' => $e->getMessage()]);
                    }
                }
                $media->delete();
                Log::info('Media deleted from database', ['media_id' => $media->id]);
            }

            $isDraft = $discussion->is_draft;
            $discussion->delete();
            Log::info($isDraft ? 'Draft deleted' : 'Discussion deleted', ['user_id' => $user->id, 'discussion_id' => $id]);
            return response()->json(['message' => $isDraft ? 'Черновик удалён' : 'Обсуждение удалено']);
        } catch (\Exception $e) {
            Log::error('Error deleting discussion', ['discussion_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error deleting discussion'], 500);
        }
    }

    public function unpublishDiscussion(Request $request, $id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $discussion = Discussion::where('user_id', $user->id)
                ->where('is_draft', false)
                ->findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'description' => 'nullable|string',
                'map' => 'nullable|array|max:10',
                'map.*.lat' => 'nullable|numeric|between:-90,90',
                'map.*.lng' => 'nullable|numeric|between:-180,180',
                'map_start' => 'nullable|array',
                'map_start.lat' => 'nullable|numeric|between:-90,90',
                'map_start.lng' => 'nullable|numeric|between:-180,180',
                'map_end' => 'nullable|array',
                'map_end.lat' => 'nullable|numeric|between:-90,90',
                'map_end.lng' => 'nullable|numeric|between:-180,180',
                'status' => 'nullable|string|in:pending,approved,rejected',
            ]);

            // Extract media IDs from the description
            $currentMediaIds = [];
            if ($discussion->description) {
                preg_match_all('/data-media-id="([^"]+)"/', $discussion->description, $matches);
                $currentMediaIds = $matches[1] ?? [];
            }

            $newMediaIds = [];
            if ($validated['description']) {
                preg_match_all('/data-media-id="([^"]+)"/', $validated['description'], $matches);
                $newMediaIds = $matches[1] ?? [];
            }

            // Delete media that are no longer referenced
            $mediaIdsToDelete = array_diff($currentMediaIds, $newMediaIds);
            if (!empty($mediaIdsToDelete)) {
                $imageKit = new ImageKit(
                    env('IMAGEKIT_PUBLIC_KEY'),
                    env('IMAGEKIT_PRIVATE_KEY'),
                    env('IMAGEKIT_URL_ENDPOINT')
                );
                foreach ($mediaIdsToDelete as $mediaId) {
                    try {
                        $media = Media::find($mediaId);
                        if ($media) {
                            $mediaContent = MediaContent::where('media_id', $mediaId)->first();
                            if ($mediaContent && $mediaContent->{$media->type . '_url'}) {
                                $fileId = basename(parse_url($mediaContent->{$media->type . '_url'}, PHP_URL_PATH));
                                $imageKit->deleteFile($fileId);
                                Log::info('Media deleted from ImageKit', ['file_id' => $fileId]);
                            }
                            $media->delete();
                            Log::info('Media deleted from database', ['media_id' => $mediaId]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete media', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
                    }
                }
            }

            $discussion->update([
                'is_draft' => true,
                'published_at' => null,
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'description' => $validated['description'] ?? '',
                'map' => $validated['map'] ?? [],
                'map_start' => $validated['map_start'] ?? $discussion->map_start,
                'map_end' => $validated['map_end'] ?? $discussion->map_end,
                'status' => $validated['status'] ?? $discussion->status,
            ]);

            Log::info('Discussion unpublished', ['user_id' => $user->id, 'discussion_id' => $discussion->id]);
            return response()->json(['message' => 'Обсуждение перемещено в черновики', 'draft' => $discussion]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error unpublishing discussion', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error unpublishing discussion', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка при снятии с публикации'], 500);
        }
    }

    public function publishDiscussion(Request $request, $id)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $discussion = Discussion::where('id', $id)->where('user_id', $user->id)->first();
            if (!$discussion) {
                return response()->json(['message' => 'Discussion not found or not accessible'], 404);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category_id' => 'required|exists:categories,id',
                'description' => 'nullable|string',
                'map' => 'nullable|array|max:10', // Максимум 10 точек
                'map.*.lat' => 'nullable|numeric|between:-90,90', // Широта
                'map.*.lng' => 'nullable|numeric|between:-180,180', // Долгота
            ]);

            Log::info('Before update', [
                'discussion_id' => $id,
                'is_draft' => $discussion->is_draft,
                'published_at' => $discussion->published_at,
                'validated_data' => $validated,
            ]);

            $updated = $discussion->update([
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'description' => $validated['description'],
                'map' => $validated['map'] ?? [], // Сохраняем как массив координат
                'is_draft' => false,
                'published_at' => now(),
                'status' => 'pending',
            ]);

            $discussion->refresh();

            Log::info('After update', [
                'discussion_id' => $id,
                'is_draft' => $discussion->is_draft,
                'published_at' => $discussion->published_at,
                'status' => $discussion->status,
                'updated' => $updated,
            ]);

            if ($discussion->is_draft) {
                Log::error('Failed to publish discussion: is_draft still true', ['discussion_id' => $id]);
                return response()->json(['message' => 'Failed to publish discussion'], 500);
            }

            return response()->json(['message' => 'Обсуждение опубликовано', 'discussion' => $discussion]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error publishing discussion', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error publishing discussion', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Error publishing discussion'], 500);
        }
    }


    public function likes($id)
    {
        try {
            $discussion = Discussion::findOrFail($id);

            $likesCount = Reaction::where('reactable_id', $id)
                ->where('reactable_type', 'App\\Models\\Discussion')
                ->where('reaction', 'like')
                ->count();

            $dislikesCount = Reaction::where('reactable_id', $id)
                ->where('reactable_type', 'App\\Models\\Discussion')
                ->where('reaction', 'dislike')
                ->count();

            $totalLikes = $likesCount - $dislikesCount;

            return response()->json(['likes' => $totalLikes]);
        } catch (\Exception $e) {
            Log::error('Error fetching discussion likes', [
                'discussion_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Error fetching likes'], 500);
        }
    }
}
