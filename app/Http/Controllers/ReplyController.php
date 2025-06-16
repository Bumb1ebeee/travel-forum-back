<?php

namespace App\Http\Controllers;

use App\Models\Discussion;
use App\Models\Media;
use App\Models\MediaContent;
use App\Models\Reply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ReplyController extends Controller
{
    private const BASE_PATH = 'replies/';
    private const CACHE_TTL_HOURS = 12; // Срок жизни кэша для ссылок

    /**
     * Добавляет новый ответ с медиа.
     */
    public function addReply(Request $request, $id)
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $discussion = Discussion::find($id);
            if (!$discussion) {
                return response()->json(['message' => 'Обсуждение не найдено'], 404);
            }

            $validated = $request->validate([
                'content' => 'nullable|string|max:1000',
                'parent_id' => 'nullable|exists:replies,id',
                'media' => 'array',
                'media.*' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,mov,avi,mp3,wav|max:10240',
            ]);

            return DB::transaction(function () use ($request, $id, $userId, $discussion, $validated) {
                $reply = Reply::create([
                    'discussion_id' => $id,
                    'user_id' => $userId,
                    'content' => $validated['content'] ?? '',
                    'parent_id' => $validated['parent_id'] ?? null,
                ]);

                if ($request->hasFile('media')) {
                    foreach ($request->file('media') as $file) {
                        $type = explode('/', $file->getClientMimeType())[0];
                        if (!in_array($type, ['image', 'video', 'audio'])) {
                            Log::warning('Недопустимый тип файла', ['type' => $type]);
                            continue;
                        }

                        $result = $this->uploadToYandexCloud($file, $type);

                        $media = Media::create([
                            'mediable_id' => $reply->id,
                            'mediable_type' => Reply::class,
                            'type' => $type,
                            'file_type' => 'file',
                            'user_id' => $userId,
                        ]);

                        $mediaContent = MediaContent::create([
                            'media_id' => $media->id,
                            'file_id' => $result['fileId'],
                            'content_type' => $type,
                            'order' => 0,
                            'image_url' => $type === 'image' ? $result['directUrl'] : null,
                            'video_url' => $type === 'video' ? $result['directUrl'] : null,
                            'music_url' => $type === 'audio' ? $result['directUrl'] : null,
                            'file_path' => $result['filePath'],
                        ]);

                        Log::info('Медиа успешно сохранено', [
                            'media_id' => $media->id,
                            'media_content_id' => $mediaContent->id,
                            'type' => $type,
                            'url' => $result['directUrl'],
                            'file_path' => $result['filePath'],
                        ]);
                    }
                }

                return response()->json(['reply' => $reply->load('user', 'media.content')], 201);
            });
        } catch (\Exception $e) {
            Log::error('Ошибка создания ответа', [
                'discussion_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка сервера: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Загрузка файла на Yandex Cloud Storage.
     */
    private function uploadToYandexCloud($file, $type)
    {
        try {
            // Проверка MIME-типа
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'video/mp4', 'audio/mpeg'])) {
                throw new \Exception('Недопустимый тип файла');
            }

            // Генерация уникального имени
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = self::BASE_PATH . "{$type}/" . $fileName;

            // Загрузка файла
            $result = Storage::disk('s3')->put($filePath, file_get_contents($file), [
                'visibility' => 'public',
                'ContentType' => $mimeType,
            ]);

            if (!$result) {
                throw new \Exception('Не удалось загрузить файл');
            }

            // Проверяем, действительно ли файл загружен
            if (!Storage::disk('s3')->exists($filePath)) {
                throw new \Exception('Файл не найден после загрузки');
            }

            // Получаем URL
            $url = Storage::disk('s3')->url($filePath) . '?disposition=inline';

            return [
                'filePath' => $filePath,
                'directUrl' => $url,
                'fileId' => md5($filePath),
            ];
        } catch (\Exception $e) {
            Log::error('Ошибка загрузки на Yandex Cloud', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Не удалось загрузить файл', 500);
        }
    }

    /**
     * Обновление прямой ссылки на файл.
     */
    public function refreshUrl(Request $request, $mediaId)
    {
        try {
            // Находим запись MediaContent
            $mediaContent = MediaContent::findOrFail($mediaId);

            // Проверяем наличие пути к файлу
            $filePath = $mediaContent->file_path;
            if (empty($filePath)) {
                Log::error('Путь к файлу отсутствует в MediaContent', ['media_id' => $mediaId]);
                return response()->json(['message' => 'Путь к файлу не найден'], 400);
            }

            // Проверяем, существует ли файл в облаке
            if (!Storage::disk('s3')->exists($filePath)) {
                Log::error('Файл не найден в Yandex Cloud', ['media_id' => $mediaId, 'path' => $filePath]);
                return response()->json(['message' => 'Файл не найден в облаке'], 404);
            }

            // Получаем новую ссылку
            $newUrl = $this->getDirectLink($filePath);

            // Обновляем ссылку в БД
            $urlKey = $mediaContent->content_type . '_url';
            $mediaContent->update([$urlKey => $newUrl]);

            Log::info('Ссылка успешно обновлена', [
                'media_id' => $mediaId,
                'filePath' => $filePath,
                'newUrl' => $newUrl,
            ]);

            return response()->json(['url' => $newUrl]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Запись MediaContent не найдена', ['media_id' => $mediaId]);
            return response()->json(['message' => 'Медиа не найдено'], 404);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления ссылки', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка сервера: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получение прямой ссылки с кэшированием.
     */
    private function getDirectLink(string $filePath): string
    {
        $cacheKey = 'yandex_cloud_direct_link_' . md5($filePath);
        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($filePath) {
            return Storage::disk('s3')->url($filePath) . '?disposition=inline';
        });
    }
}
