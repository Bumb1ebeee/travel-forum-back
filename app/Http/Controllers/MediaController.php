<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    private const CACHE_TTL_HOURS = 12; // Срок жизни кэша для ссылок
    private const BASE_PATH = 'media/discussions/';

    public function __construct()
    {
        //
    }

    /**
     * Обновление прямой ссылки на файл.
     */
    public function refreshUrl(Request $request, $mediaId)
    {
        try {
            $mediaContent = MediaContent::findOrFail($mediaId);
            $filePath = $mediaContent->file_path;
            if (empty($filePath)) {
                return response()->json(['message' => 'Путь к файлу не найден'], 400);
            }

            $directUrl = $this->getDirectLink($filePath);

            $urlKey = $mediaContent->content_type . '_url';
            $mediaContent->update([$urlKey => $directUrl]);

            return response()->json(['url' => $directUrl]);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления ссылки', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка обновления ссылки: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получение прямой ссылки с кэшированием.
     */
    private function getDirectLink(string $filePath): string
    {
        $cacheKey = 'yandex_cloud_direct_link_' . md5($filePath);
        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($filePath) {
            return Storage::disk('s3')->url($filePath);
        });
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

            // Генерация имени и пути
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = self::BASE_PATH . "{$type}/" . $fileName;

            // Загрузка файла в облако
            $result = Storage::disk('s3')->put($filePath, file_get_contents($file), [
                'visibility' => 'public',
                'ContentType' => $mimeType,
            ]);

            if (!$result) {
                throw new \Exception('Не удалось загрузить файл');
            }

            // Проверка существования файла
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
     * Удаление файла с Yandex Cloud Storage.
     */
    private function deleteFromYandexCloud(string $filePath): bool
    {
        try {
            if (Storage::disk('s3')->exists($filePath)) {
                Storage::disk('s3')->delete($filePath);
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Ошибка удаления файла', ['path' => $filePath, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Добавление нового медиа.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) return response()->json(['message' => 'Требуется авторизация'], 401);

            $validated = $request->validate([
                'mediable_id' => 'required|integer',
                'mediable_type' => 'required|string|in:App\\Models\\Discussion',
                'type' => 'required|string|in:image,video,music,text,map',
                'text_content' => 'nullable|string|required_if:type,text',
                'map_points' => 'nullable|array|required_if:type,map',
                'file' => 'nullable|file|mimes:jpg,jpeg,png,mp4,mp3|max:102400',
            ]);

            $mediableType = str_replace(['\\', '/'], '\\', $validated['mediable_type']);
            $model = app($mediableType)::find($validated['mediable_id']);
            if (!$model || ($mediableType === 'App\\Models\\Discussion' && $model->user_id !== $user->id)) {
                return response()->json(['message' => 'Модель не найдена или доступ запрещён'], 404);
            }

            $media = Media::create([
                'mediable_id' => $validated['mediable_id'],
                'mediable_type' => $mediableType,
                'type' => $validated['type'],
                'file_type' => in_array($validated['type'], ['image', 'video', 'music']) ? 'file' : null,
                'user_id' => $user->id,
            ]);

            $contentData = [
                'media_id' => $media->id,
                'file_id' => $media->id,
                'content_type' => $validated['type'],
                'order' => 0,
            ];

            if ($validated['type'] === 'text') {
                $contentData['text_content'] = $validated['text_content'];
            } elseif ($validated['type'] === 'map') {
                $contentData['map_points'] = json_encode($validated['map_points']);
            } elseif (in_array($validated['type'], ['image', 'video', 'music'])) {
                if (!$request->hasFile('file')) {
                    return response()->json(['message' => 'Файл обязателен для данного типа медиа'], 400);
                }

                $file = $request->file('file');
                $result = $this->uploadToYandexCloud($file, $validated['type']);

                $urlKey = $validated['type'] . '_url';
                $contentData[$urlKey] = $result['directUrl'];
                $contentData['file_id'] = $result['fileId'];
                $contentData['file_path'] = $result['filePath'];
            }

            $mediaContent = MediaContent::create($contentData);

            return response()->json([
                'media' => [
                    'id' => $media->id,
                    'mediable_id' => $media->mediable_id,
                    'mediable_type' => $media->mediable_type,
                    'type' => $media->type,
                    'file_type' => $media->file_type,
                    'content' => [
                        'file_id' => $mediaContent->file_id,
                        'content_type' => $mediaContent->content_type,
                        'order' => $mediaContent->order,
                        'image_url' => $mediaContent->image_url,
                        'video_url' => $mediaContent->video_url,
                        'music_url' => $mediaContent->music_url,
                        'text_content' => $mediaContent->text_content,
                        'map_points' => $mediaContent->map_points,
                        'file_path' => $mediaContent->file_path,
                    ],
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Ошибка создания медиа:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка сервера'], 500);
        }
    }

    /**
     * Удаление медиа.
     */
    public function destroy($id)
    {
        try {
            $media = Media::find($id);
            if (!$media) return response()->json(['message' => 'Медиа не найдено'], 404);

            $user = Auth::guard('sanctum')->user();
            if (!$user) return response()->json(['message' => 'Требуется авторизация'], 401);

            if ($media->mediable_type === 'App\\Models\\Discussion') {
                $discussion = $media->mediable()->first();
                if (!$discussion || $discussion->user_id !== $user->id) {
                    return response()->json(['message' => 'Доступ запрещён'], 403);
                }
            }

            $mediaContent = MediaContent::where('media_id', $id)->first();

            if ($mediaContent && in_array($media->type, ['image', 'video', 'music'])) {
                $filePath = $mediaContent->file_path;
                $this->deleteFromYandexCloud($filePath);
            }

            if ($mediaContent) $mediaContent->delete();
            $media->delete();

            return response()->json(['message' => 'Медиа успешно удалено']);
        } catch (\Exception $e) {
            Log::error('Ошибка удаления медиа:', [
                'media_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка сервера'], 500);
        }
    }

    /**
     * Получение прямой ссылки.
     */
    public function getDirectUrl(Request $request, $mediaId)
    {
        return $this->refreshUrl($request, $mediaId);
    }

    public function index(Request $request)
    {
        try {
            Log::debug('Начало обработки запроса медиа', ['request' => $request->all()]);

            $validated = $request->validate([
                'mediable_id' => 'required|integer',
                'mediable_type' => 'required|string',
            ]);

            $mediableType = $validated['mediable_type'];
            Log::debug('Обработанный mediable_type', ['mediable_type' => $mediableType]);

            if (!class_exists($mediableType)) {
                Log::warning('Недопустимый mediable_type', [
                    'mediable_type' => $mediableType,
                    'class_exists_manual' => class_exists('App\\Models\\Discussion'),
                ]);
                return response()->json(['message' => 'Недопустимый mediable_type'], 400);
            }

            $model = app($mediableType)::find($validated['mediable_id']);
            if (!$model) {
                Log::warning('Модель не найдена', [
                    'mediable_id' => $validated['mediable_id'],
                    'mediable_type' => $mediableType,
                ]);
                return response()->json(['message' => 'Модель не найдена'], 404);
            }

            $media = $model->media()->with(['content' => function ($query) {
                $query->select('id', 'media_id', 'file_id', 'content_type', 'order', 'image_url', 'video_url', 'music_url', 'text_content', 'map_points');
            }])->get();

            Log::debug('Загруженные медиа', [
                'media_count' => $media->count(),
                'media_ids' => $media->pluck('id')->toArray(),
                'content_loaded' => $media->pluck('content')->toArray(),
            ]);

            $formattedMedia = $media->map(function ($item) {
                $content = $item->content;
                Log::debug('Форматирование медиа', [
                    'media_id' => $item->id,
                    'content_exists' => !empty($content),
                    'content_type' => $content ? $content->content_type : $item->type,
                    'content_data' => $content ? $content->toArray() : null,
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

            Log::info('Медиа успешно получены', [
                'mediable_id' => $validated['mediable_id'],
                'mediable_type' => $mediableType,
                'media_count' => $media->count(),
            ]);

            return response()->json(['media' => $formattedMedia]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Ошибка валидации медиа', [
                'errors' => $e->errors(),
                'request' => $request->all(),
            ]);
            return response()->json(['message' => 'Ошибка валидации', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка получения медиа', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json(['message' => 'Ошибка получения медиа'], 500);
        }
    }
}
