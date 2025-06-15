<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;

class MediaController extends Controller
{
    private $yandexToken;
    private const YANDEX_BASE_PATH = '/media/discussions/';
    private const CACHE_TTL_HOURS = 12; // Срок действия кэшированных ссылок

    public function __construct()
    {
        $this->yandexToken = config('services.yandex_disk.token');

        if (empty($this->yandexToken) || !is_string($this->yandexToken) || strlen($this->yandexToken) < 10) {
            Log::error('Некорректный или отсутствующий Yandex Disk token в .env');
            throw new \Exception('Некорректный или отсутствующий Yandex Disk token');
        }
    }

    /**
     * Обновление прямой ссылки на файл.
     *
     * @param Request $request
     * @param int $mediaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshUrl(Request $request, $mediaId)
    {
        try {
            // Находим запись MediaContent
            $mediaContent = MediaContent::findOrFail($mediaId);

            // Проверяем наличие пути к файлу
            $filePath = $mediaContent->file_path;
            if (empty($filePath)) {
                Log::error('Путь к файлу отсутствует в базе данных', ['media_id' => $mediaId]);
                return response()->json(['message' => 'Путь к файлу не найден'], 400);
            }

            // Проверяем существование файла на Яндекс.Диске
            if (!$this->checkFileExistsOnYandex($filePath)) {
                Log::error('Файл не найден на Яндекс.Диске', ['media_id' => $mediaId, 'path' => $filePath]);
                return response()->json(['message' => 'Файл не найден на Яндекс.Диске'], 404);
            }

            // Получаем новую прямую ссылку (с кэшированием)
            $newUrl = $this->getYandexDirectLink($filePath);

            // Обновляем ссылку в базе данных
            $urlKey = $mediaContent->content_type . '_url';
            $mediaContent->update([$urlKey => $newUrl]);

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
            return response()->json(['message' => 'Ошибка обновления ссылки: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Получение прямой ссылки на файл с кэшированием.
     *
     * @param string $filePath
     * @return string
     * @throws \Exception
     */
    private function getYandexDirectLink($filePath)
    {
        $cacheKey = 'yandex_direct_link_' . md5($filePath);
        $cachedUrl = Cache::get($cacheKey);

        if ($cachedUrl) {
            return $cachedUrl;
        }

        try {
            $client = new Client();
            $response = $client->get("https://cloud-api.yandex.net/v1/disk/resources/download", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);

            $data = json_decode($response->getBody(), true);
            $directUrl = $data['href'] ?? null;

            if (!$directUrl) {
                throw new \Exception('Не удалось получить прямую ссылку на файл');
            }

            // Кэшируем ссылку
            Cache::put($cacheKey, $directUrl, now()->addHours(self::CACHE_TTL_HOURS));
            return $directUrl;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('Ошибка HTTP-запроса к Яндекс.Диску', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Ошибка получения ссылки: ' . $e->getMessage(), 503);
        } catch (\Exception $e) {
            Log::error('Общая ошибка получения прямой ссылки', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Проверка существования файла на Яндекс.Диске.
     *
     * @param string $filePath
     * @return bool
     */
    private function checkFileExistsOnYandex($filePath)
    {
        try {
            $client = new Client();
            $client->get("https://cloud-api.yandex.net/v1/disk/resources", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);
            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            Log::error('Ошибка проверки существования файла', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Загрузка файла на Яндекс.Диск.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $type
     * @return array
     * @throws \Exception
     */
    private function uploadToYandexDisk($file, $type)
    {
        try {
            // Проверка MIME-типа
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'video/mp4', 'audio/mpeg'])) {
                throw new \Exception('Недопустимый тип файла');
            }

            $fileName = uniqid() . '_' . $file->getClientOriginalName();
            $filePath = self::YANDEX_BASE_PATH . "{$type}/{$fileName}";

            // Проверяем/создаём папки
            $this->ensureYandexDiskFolder(self::YANDEX_BASE_PATH . $type);

            // Получаем ссылку для загрузки
            $client = new Client();
            $uploadUrlResponse = $client->get("https://cloud-api.yandex.net/v1/disk/resources/upload", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath, 'overwrite' => true],
            ]);

            $uploadData = json_decode($uploadUrlResponse->getBody(), true);
            $uploadHref = $uploadData['href'];

            // Загружаем файл
            $client->put($uploadHref, [
                'headers' => ['Content-Type' => $mimeType],
                'body' => fopen($file->getPathname(), 'rb'),
            ]);

            // Публикуем файл
            $client->put("https://cloud-api.yandex.net/v1/disk/resources/publish", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);

            // Получаем прямую ссылку
            $directLink = $this->getYandexDirectLink($filePath);

            return [
                'filePath' => $filePath,
                'directUrl' => $directLink,
                'fileId' => md5($filePath),
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('Ошибка HTTP-запроса к Яндекс.Диску', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Ошибка загрузки файла: ' . $e->getMessage(), 503);
        } catch (\Exception $e) {
            Log::error('Общая ошибка загрузки на Яндекс.Диск', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Не удалось загрузить файл', 500);
        }
    }

    /**
     * Проверяет и создаёт папку на Яндекс.Диске, если она не существует.
     *
     * @param string $path
     * @return bool
     * @throws \Exception
     */
    private function ensureYandexDiskFolder($path)
    {
        $cacheKey = 'yandex_folder_exists_' . md5($path);
        if (Cache::get($cacheKey)) {
            return true;
        }

        try {
            $client = new Client();
            try {
                $client->get("https://cloud-api.yandex.net/v1/disk/resources", [
                    'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                    'query' => ['path' => $path],
                ]);
                Cache::put($cacheKey, true, now()->addDays(1));
                return true;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                if ($e->getResponse()->getStatusCode() !== 404) {
                    throw $e;
                }
                $client->put("https://cloud-api.yandex.net/v1/disk/resources", [
                    'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                    'query' => ['path' => $path],
                ]);
                Cache::put($cacheKey, true, now()->addDays(1));
                return true;
            }
        } catch (\Exception $e) {
            Log::error('Ошибка создания папки на Яндекс.Диске', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Получение прямой ссылки для использования в API.
     *
     * @param Request $request
     * @param int $mediaId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDirectUrl(Request $request, $mediaId)
    {
        return $this->refreshUrl($request, $mediaId);
    }

    /**
     * Добавление нового медиа.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

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
                $result = $this->uploadToYandexDisk($file, $validated['type']);

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
     * Удаление файла с Яндекс.Диска.
     */
    private function deleteFromYandexDisk($filePath)
    {
        try {
            $client = new Client();
            $client->delete("https://cloud-api.yandex.net/v1/disk/resources", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => [
                    'path' => $filePath,
                    'permanently' => true,
                ],
            ]);
            Log::info('Файл успешно удалён с Яндекс.Диска', ['path' => $filePath]);
            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                Log::warning('Файл не найден на Яндекс.Диске', ['path' => $filePath]);
                return true;
            }
            throw $e;
        }
    }

    /**
     * Удаление медиа.
     */
    public function destroy($id)
    {
        try {
            $media = Media::find($id);
            if (!$media) {
                Log::warning('Медиа не найдено', ['media_id' => $id]);
                return response()->json(['message' => 'Медиа не найдено'], 404);
            }

            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                Log::warning('Пользователь не аутентифицирован', ['media_id' => $id]);
                return response()->json(['message' => 'Требуется авторизация'], 401);
            }

            if ($media->mediable_type === 'App\\Models\\Discussion') {
                $discussion = $media->mediable()->first();
                if (!$discussion || $discussion->user_id !== $user->id) {
                    Log::warning('Попытка удаления медиа без доступа', [
                        'media_id' => $id,
                        'user_id' => $user->id,
                    ]);
                    return response()->json(['message' => 'Доступ запрещён'], 403);
                }
            }

            $mediaContent = MediaContent::where('media_id', $id)->first();

            if ($mediaContent && in_array($media->type, ['image', 'video', 'music'])) {
                $filePath = $mediaContent->file_path;
                if ($filePath) {
                    $this->deleteFromYandexDisk($filePath);
                }
            }

            if ($mediaContent) {
                $mediaContent->delete();
                Log::info('Запись media_content удалена', ['media_id' => $id]);
            }

            $media->delete();
            Log::info('Медиа успешно удалено', ['media_id' => $id]);

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
}
