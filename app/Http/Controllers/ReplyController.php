<?php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\Media;
use App\Models\MediaContent;
use App\Models\Notification;
use App\Models\Reply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use ImageKit\ImageKit;

class ReplyController extends Controller
{
    protected $imagekit;

    public function __construct()
    {
        try {
            $this->imagekit = new ImageKit(
                env('IMAGEKIT_PUBLIC_KEY'),
                env('IMAGEKIT_PRIVATE_KEY'),
                env('IMAGEKIT_URL_ENDPOINT')
            );
            Log::debug('ImageKit инициализирован в ReplyController');
        } catch (\Exception $e) {
            Log::error('Ошибка инициализации ImageKit в ReplyController', ['error' => $e->getMessage()]);
            throw new \Exception('Ошибка конфигурации ImageKit');
        }
    }

    private function uploadToImageKit($file, $type)
    {
        try {
            $fileName = uniqid() . '_' . $file->getClientOriginalName();
            $uploadOptions = [
                'fileName' => $fileName,
                'folder' => "/replies/{$type}",
                'useUniqueFileName' => true,
            ];

            Log::info('Загрузка в ImageKit', ['file' => $fileName, 'type' => $type]);
            $result = $this->imagekit->uploadFile([
                'file' => fopen($file->getPathname(), 'r'),
                'fileName' => $fileName,
                'folder' => $uploadOptions['folder'],
                'useUniqueFileName' => $uploadOptions['useUniqueFileName'],
            ]);

            if (isset($result->error)) {
                throw new \Exception('Ошибка загрузки в ImageKit: ' . $result->error->message);
            }

            Log::info('Успешная загрузка в ImageKit', ['url' => $result->result->url, 'fileId' => $result->result->fileId]);
            return ['url' => $result->result->url, 'fileId' => $result->result->fileId];
        } catch (\Exception $e) {
            Log::error('Ошибка загрузки в ImageKit', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
                'type' => $type,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function addReply(Request $request, $id)
    {
        try {
            Log::info('Request data:', $request->all());

            $validated = $request->validate([
                'content' => 'nullable|string|max:1000',
                'parent_id' => 'nullable|exists:replies,id',
                'media.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,mov,mp3,wav|max:10240',
            ]);

            Log::info('Validated data:', $validated);

            $discussion = Discussion::findOrFail($id);
            $userId = Auth::id();

            if (!$discussion->members()->where('user_id', $userId)->exists()) {
                return response()->json(['message' => 'Вы не присоединились к обсуждению'], 403);
            }

            if (empty($validated['content']) && !$request->hasFile('media')) {
                return response()->json(['message' => 'Необходимо добавить текст или файл'], 422);
            }

            // Проверка уровня вложенности
            if (isset($validated['parent_id'])) {
                $parentReply = Reply::find($validated['parent_id']);
                if ($parentReply && $parentReply->parent_id !== null) {
                    return response()->json(['message' => 'Нельзя создавать ответы глубже второго уровня'], 422);
                }
            }

            $reply = Reply::create([
                'discussion_id' => $id,
                'user_id' => $userId,
                'content' => $validated['content'] ?? '',
                'parent_id' => isset($validated['parent_id']) ? $validated['parent_id'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Обработка медиа
            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $file) {
                    $fileType = $file->getClientMimeType();
                    $contentType = null;
                    if (str_starts_with($fileType, 'image/')) {
                        $contentType = 'image';
                    } elseif (str_starts_with($fileType, 'video/')) {
                        $contentType = 'video';
                    } elseif (str_starts_with($fileType, 'audio/')) {
                        $contentType = 'music';
                    }

                    if (!$contentType) {
                        Log::error('Некорректный тип файла', ['file_type' => $fileType]);
                        continue;
                    }

                    $uploadResult = $this->uploadToImageKit($file, $contentType);

                    $media = Media::create([
                        'mediable_id' => $reply->id,
                        'mediable_type' => 'App\\Models\\Reply',
                        'type' => $contentType,
                        'file_type' => $fileType,
                        'user_id' => $userId,
                    ]);

                    MediaContent::create([
                        'media_id' => $media->id,
                        'file_id' => $uploadResult['fileId'],
                        'content_type' => $contentType,
                        'order' => 0,
                        'image_url' => $contentType === 'image' ? $uploadResult['url'] : null,
                        'video_url' => $contentType === 'video' ? $uploadResult['url'] : null,
                        'music_url' => $contentType === 'music' ? $uploadResult['url'] : null,
                    ]);
                }
            }

            Log::info('Reply created', [
                'reply_id' => $reply->id,
                'discussion_id' => $id,
                'user_id' => $userId,
                'parent_id' => isset($validated['parent_id']) ? $validated['parent_id'] : null,
            ]);

            if ($discussion->user_id !== Auth::id()) {
                Notification::createNotification(
                    $discussion->user_id,
                    'discussion_reply',
                    "Новый ответ в вашем обсуждении '{$discussion->title}'.",
                    "/discussions/{$discussion->id}"
                );
            }

            if (isset($validated['parent_id'])) {
                $parentReply = Reply::find($validated['parent_id']);
                if ($parentReply) {
                    Log::info('Creating notification for reply reply', [
                        'parent_reply_id' => $parentReply->id,
                        'user_id' => $parentReply->user_id,
                        'auth_id' => Auth::id(),
                    ]);
                    if ($parentReply->user_id !== Auth::id()) {
                        Notification::createNotification(
                            $parentReply->user_id,
                            'reply_reply',
                            "Пользователь ответил на ваш ответ в обсуждении '{$discussion->title}'.",
                            "/discussions/{$discussion->id}#reply-{$reply->id}"
                        );
                    }
                } else {
                    Log::warning('Parent reply not found', ['parent_id' => $validated['parent_id']]);
                }
            }

            $reply->load('user', 'parent.user', 'children', 'reactions', 'media.content');
            $reply->likes = $reply->reactions->where('reaction', 'like')->count() - $reply->reactions->where('reaction', 'dislike')->count();
            $userReaction = $reply->reactions->where('user_id', $userId)->first();
            $reply->userReaction = $userReaction ? $userReaction->reaction : null;
            unset($reply->reactions);
            return response()->json(['reply' => $reply], 201);
        } catch (\Exception $e) {
            Log::error('Error creating reply', [
                'discussion_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка при создании ответа'], 500);
        }
    }
}
