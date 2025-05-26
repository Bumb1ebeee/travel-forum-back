<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ImageKit\ImageKit;

class MediaController extends Controller
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
            Log::debug('ImageKit инициализирован', [
                'public_key' => env('IMAGEKIT_PUBLIC_KEY'),
                'url_endpoint' => env('IMAGEKIT_URL_ENDPOINT')
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка инициализации ImageKit', ['error' => $e->getMessage()]);
            throw new \Exception('Ошибка конфигурации ImageKit');
        }
    }

    private function uploadToImageKit($file, $type)
    {
        try {
            $fileName = uniqid() . '_' . $file->getClientOriginalName();
            $uploadOptions = [
                'fileName' => $fileName,
                'folder' => "/media/{$type}",
                'useUniqueFileName' => true,
            ];

            Log::info('Загрузка в ImageKit', ['file' => $fileName, 'type' => $type]);
            $result = $this->imageKit->uploadFile([
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

    public function uploadImage(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,mp4,mov,mp3,wav|max:25600',
                'file_type' => 'required|string',
            ]);

            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                Log::warning('Попытка загрузки файла без авторизации');
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $file = $request->file('file');
            $fileType = $request->input('file_type');

            // Используем условные операторы вместо тернарных
            if (str_starts_with($fileType, 'image/')) {
                $contentType = 'image';
            } elseif (str_starts_with($fileType, 'video/')) {
                $contentType = 'video';
            } elseif (str_starts_with($fileType, 'audio/')) {
                $contentType = 'music';
            } else {
                $contentType = null;
            }

            if (!$contentType) {
                Log::error('Некорректный тип файла', ['file_type' => $fileType]);
                return response()->json(['message' => 'Некорректный тип файла'], 400);
            }

            $result = $this->uploadToImageKit($file, $contentType);

            Log::info('Файл успешно загружен', ['url' => $result['url'], 'fileId' => $result['fileId']]);

            return response()->json([
                'url' => $result['url'],
                'mediaId' => $result['fileId'],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Ошибка валидации при загрузке файла', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Ошибка валидации', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка загрузки файла', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка загрузки файла: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            Log::debug('Начало метода store', [
                'request' => $request->all(),
                'raw_mediable_type' => $request->input('mediable_type'),
            ]);
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            $validated = $request->validate([
                'mediable_id' => 'required|integer',
                'mediable_type' => 'required|string|in:App\\Models\\Discussion',
                'type' => 'required|string|in:image,video,music,text,map',
                'file' => 'nullable|file|mimes:jpg,jpeg,png,mp4,mp3|max:102400',
                'text_content' => 'nullable|string|required_if:type,text',
                'map_points' => 'nullable|array|required_if:type,map',
            ]);

            Log::debug('Валидация пройдена', ['validated' => $validated]);

            $mediableType = str_replace(['\\', '/'], '\\', $validated['mediable_type']);
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
                return response()->json(['message' => 'Модель не найдена'], 404);
            }

            if ($mediableType === 'App\\Models\\Discussion' && $model->user_id !== $user->id) {
                return response()->json(['message' => 'Доступ запрещён'], 403);
            }

            $media = Media::create([
                'mediable_id' => $validated['mediable_id'],
                'mediable_type' => $mediableType,
                'type' => $validated['type'],
                'file_type' => $validated['type'] === 'text' ? null : ($request->file('file') ? $request->file('file')->getClientMimeType() : null),
                'user_id' => $user->id,
            ]);

            $mediaContentData = [
                'media_id' => $media->id,
                'file_id' => $media->id,
                'content_type' => $validated['type'],
                'order' => 0,
                'text_content' => $validated['type'] === 'text' ? ($validated['text_content'] ?? '') : null,
            ];

            if ($validated['type'] === 'map') {
                $mediaContentData['map_points'] = $validated['map_points'] ?? [];
            } elseif ($validated['type'] !== 'text') {
                if (!$request->hasFile('file')) {
                    return response()->json(['message' => 'Файл обязателен для данного типа медиа'], 400);
                }
                $file = $request->file('file');
                $fileName = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
                $uploadResponse = $this->imagekit->uploadFile([
                    'file' => fopen($file->getPathname(), 'r'),
                    'fileName' => $fileName,
                    'folder' => "/discussions/{$validated['mediable_id']}",
                    'tags' => ['discussion', $validated['type']],
                    'useUniqueFileName' => true,
                ]);

                if (!$uploadResponse || $uploadResponse->error || !isset($uploadResponse->result->fileId)) {
                    return response()->json(['message' => 'Ошибка загрузки файла в ImageKit.io'], 500);
                }

                $mediaContentData = array_merge($mediaContentData, [
                    'file_id' => $uploadResponse->result->fileId,
                    'image_url' => $validated['type'] === 'image' ? $uploadResponse->result->url : null,
                    'video_url' => $validated['type'] === 'video' ? $uploadResponse->result->url : null,
                    'music_url' => $validated['type'] === 'music' ? $uploadResponse->result->url : null,
                ]);
            }

            $mediaContent = MediaContent::create($mediaContentData);
            Log::info('Медиа создано', [
                'media_id' => $media->id,
                'content_id' => $mediaContent->id,
                'mediable_type' => $mediableType,
                'text_content' => $mediaContentData['text_content'],
            ]);

            return response()->json(['media' => [
                'id' => $media->id,
                'mediable_id' => $media->mediable_id,
                'mediable_type' => $media->mediable_type,
                'type' => $media->type,
                'file_type' => $media->file_type,
                'content' => [
                    'file_id' => $mediaContent->file_id,
                    'content_type' => $mediaContent->content_type,
                    'text_content' => $mediaContent->text_content,
                    'image_url' => $mediaContent->image_url,
                    'video_url' => $mediaContent->video_url,
                    'music_url' => $mediaContent->music_url,
                    'map_points' => $mediaContent->map_points,
                    'order' => $mediaContent->order,
                ],
            ]], 201);
        } catch (\Exception $e) {
            Log::error('Ошибка создания медиа', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json(['message' => 'Ошибка создания медиа: ' . $e->getMessage()], 500);
        }
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

    public function update(Request $request, $id)
    {
        try {
            // Находим медиа и связанный контент
            $media = Media::findOrFail($id);
            $mediaContent = MediaContent::where('media_id', $media->id)->firstOrFail();

            // Условная валидация в зависимости от типа медиа
            if ($media->type === 'text') {
                $validated = $request->validate([
                    'text_content' => 'required|string|max:65535',
                ]);

                Log::debug('Updating text media:', [
                    'id' => $id,
                    'text_content' => $validated['text_content'],
                ]);

                // Обновляем текстовое содержимое
                $mediaContent->text_content = $validated['text_content'];
                $mediaContent->content_type = 'text/plain';
                $mediaContent->save();

                Log::info('Text media updated successfully:', ['media_id' => $media->id]);
            } else {
                $validated = $request->validate([
                    'file' => 'required|file|mimes:jpeg,png,gif,webp,mp4,mov,mp3,wav|max:102400',
                    'file_type' => 'required|string',
                    'type' => 'required|string|in:image,video,music',
                    'mediable_id' => 'required',
                    'mediable_type' => 'required|string',
                ]);

                Log::debug('Media update input:', ['id' => $id, 'validated' => $validated]);

                // Проверка соответствия типа и владельца
                if (
                    $media->type !== $validated['type'] ||
                    $media->mediable_id != $validated['mediable_id'] ||
                    $media->mediable_type !== $validated['mediable_type']
                ) {
                    return response()->json(['error' => 'Недопустимое изменение типа или владельца медиа'], 400);
                }

                $file = $request->file('file');
                if (!$file->isValid()) {
                    Log::error('Загружаемый файл невалиден', ['file' => $file->getClientOriginalName()]);
                    return response()->json(['error' => 'Загружаемый файл невалиден'], 400);
                }

                $fileName = $file->getClientOriginalName();
                $path = 'discussions/' . $validated['type'] . '/' . $media->id . '_' . $fileName;

                // Удаление старого файла из ImageKit
                if ($mediaContent->{$media->type . '_url'}) {
                    $fileId = basename(parse_url($mediaContent->{$media->type . '_url'}, PHP_URL_PATH));
                    try {
                        $this->imageKit->deleteFile($fileId);
                        Log::info('Old file deleted from ImageKit:', ['fileId' => $fileId]);
                    } catch (\Exception $e) {
                        Log::warning('Не удалось удалить старый файл из ImageKit: ' . $e->getMessage(), [
                            'fileId' => $fileId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Загрузка нового файла
                Log::debug('Uploading file to ImageKit:', ['path' => $path, 'fileName' => $fileName]);

                try {
                    $uploadResult = $this->imageKit->uploadFile([
                        'file' => base64_encode(file_get_contents($file->getPathname())),
                        'fileName' => $fileName,
                        'folder' => '/discussions/' . $validated['type'],
                        'useUniqueFileName' => true,
                    ]);

                    Log::debug('ImageKit upload response:', (array)$uploadResult);

                    if (!$uploadResult || !isset($uploadResult->result) || empty($uploadResult->result->url)) {
                        Log::error('ImageKit upload failed: invalid response', [
                            'response' => (array)$uploadResult,
                            'fileName' => $fileName,
                        ]);
                        return response()->json(['error' => 'Не удалось загрузить файл в ImageKit: пустой результат'], 500);
                    }

                    $urlColumn = $validated['type'] . '_url';
                    $mediaContent->$urlColumn = $uploadResult->result->url;
                    $mediaContent->content_type = $file->getMimeType();
                } catch (\Exception $e) {
                    Log::error('ImageKit upload exception', [
                        'error' => $e->getMessage(),
                        'fileName' => $fileName,
                        'trace' => $e->getTraceAsString(),
                    ]);
                    return response()->json(['error' => 'Ошибка загрузки в ImageKit: ' . $e->getMessage()], 500);
                }

                $media->file_type = $validated['file_type'];
                $media->save();

                $mediaContent->save();

                Log::info('Media updated successfully:', [
                    'media_id' => $media->id,
                    'url' => $mediaContent->{$media->type . '_url'},
                ]);
            }

            // Формируем ответ API
            return response()->json([
                'media' => [
                    'id' => $media->id,
                    'type' => $media->type,
                    'file_type' => $media->file_type,
                    'content' => [
                        'file_id' => $mediaContent->file_id,
                        'content_type' => $mediaContent->content_type,
                        'order' => $mediaContent->order,
                        'text_content' => $mediaContent->text_content,
                        'image_url' => $mediaContent->image_url,
                        'video_url' => $mediaContent->video_url,
                        'music_url' => $mediaContent->music_url,
                        'map_points' => $mediaContent->map_points,
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            Log::error('Validation error updating media:', [
                'id' => $id,
                'errors' => $e->errors(),
            ]);
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении медиа: ' . $e->getMessage(), [
                'id' => $id,
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Ошибка сервера'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Log::debug('Попытка удаления медиа', ['media_id' => $id]);
            $media = Media::find($id);
            if (!$media) {
                Log::warning('Медиа не найдено для удаления', ['media_id' => $id]);
                return response()->json(['message' => 'Медиа не найдено'], 404);
            }
            $mediaContent = $media->content;
//            if ($mediaContent && in_array($media->type, ['image', 'video', 'music'])) {
//                $this->deleteImageKitFile($mediaContent->file_id);
//            }
            $media->delete();
            Log::info('Media deleted successfully:', ['media_id' => $id]);
            return response()->json(['message' => 'Медиа успешно удалено']);
        } catch (\Exception $e) {
            Log::error('Ошибка при удалении медиа:', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка при удалении медиа'], 500);
        }
    }
}
