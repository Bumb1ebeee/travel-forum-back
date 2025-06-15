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
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;

class ReplyController extends Controller
{
    private $yandexToken;
    private const YANDEX_BASE_PATH = '/replies/';
    private const CACHE_TTL_HOURS = 12; // Срок действия кэшированных ссылок
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif',
        'video/mp4', 'video/quicktime', 'video/x-msvideo',
        'audio/mpeg', 'audio/wav',
    ];

    /**
     * Initialize the controller with Yandex.Disk token validation.
     */
    public function __construct()
    {
        $this->yandexToken = config('services.yandex_disk.token');

        if (empty($this->yandexToken) || !is_string($this->yandexToken) || strlen($this->yandexToken) < 10) {
            Log::error('Некорректный или отсутствующий Yandex Disk token в .env');
            throw new \Exception('Некорректный или отсутствующий Yandex Disk token');
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
                if ($e->getResponse()->getStatusCode() === 404) {
                    $client->put("https://cloud-api.yandex.net/v1/disk/resources", [
                        'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                        'query' => ['path' => $path],
                    ]);
                    Cache::put($cacheKey, true, now()->addDays(1));
                    return true;
                }
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Ошибка создания папки на Яндекс.Диске', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
                throw new \Exception('Недопустимый тип файла: ' . $mimeType);
            }

            $fileName = uniqid() . '_' . $file->getClientOriginalName();
            $filePath = self::YANDEX_BASE_PATH . "{$type}/" . urlencode($fileName);

            Log::info('Загрузка файла на Яндекс.Диск', [
                'fileName' => $fileName,
                'filePath' => $filePath,
                'mimeType' => $mimeType,
            ]);

            // Проверяем/создаём папки
            $this->ensureYandexDiskFolder(self::YANDEX_BASE_PATH);
            $this->ensureYandexDiskFolder(self::YANDEX_BASE_PATH . $type);

            // Получаем ссылку для загрузки
            $client = new Client();
            $uploadUrlResponse = $client->get("https://cloud-api.yandex.net/v1/disk/resources/upload", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath, 'overwrite' => true],
            ]);

            $uploadData = json_decode($uploadUrlResponse->getBody(), true);
            $uploadHref = $uploadData['href'] ?? null;

            if (!$uploadHref) {
                throw new \Exception('Не удалось получить URL для загрузки');
            }

            // Загружаем файл
            $client->put($uploadHref, [
                'headers' => ['Content-Type' => $mimeType],
                'body' => fopen($file->getPathname(), 'rb'),
            ]);

            // Получаем временную ссылку для скачивания
            $downloadLinkResponse = $client->get("https://cloud-api.yandex.net/v1/disk/resources/download", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);

            $downloadData = json_decode($downloadLinkResponse->getBody(), true);
            $directUrl = $downloadData['href'] ?? null;

            if (!$directUrl || !preg_match('/^https:\/\/downloader\.disk\.yandex\.ru\/disk\//', $directUrl)) {
                Log::error('Не удалось получить корректную временную ссылку', [
                    'filePath' => $filePath,
                    'downloadData' => $downloadData,
                ]);
                throw new \Exception('Не удалось получить временную ссылку');
            }

            // Кэшируем ссылку
            $cacheKey = 'yandex_download_url_' . md5($filePath);
            Cache::put($cacheKey, $directUrl, now()->addHours(self::CACHE_TTL_HOURS));

            Log::info('Файл успешно загружен', [
                'filePath' => $filePath,
                'directUrl' => $directUrl,
            ]);

            return [
                'filePath' => $filePath,
                'directUrl' => $directUrl,
                'fileId' => md5($filePath),
                'type' => $type,
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
     * Creates a new reply to a discussion with optional media attachments.
     *
     * @param Request $request
     * @param int $id Discussion ID
     * @return \Illuminate\Http\JsonResponse
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
                            Log::warning('Пропущен недопустимый тип медиа', ['type' => $type]);
                            continue;
                        }

                        $result = $this->uploadToYandexDisk($file, $type);

                        // Проверяем формат URL
                        if (!preg_match('/^https:\/\/downloader\.disk\.yandex\.ru\/disk\//', $result['directUrl'])) {
                            Log::error('Обнаружен некорректный формат URL', [
                                'url' => $result['directUrl'],
                                'filePath' => $result['filePath'],
                            ]);
                            throw new \Exception('Некорректный формат временной ссылки');
                        }

                        $media = Media::create([
                            'mediable_id' => $reply->id,
                            'mediable_type' => Reply::class,
                            'type' => $result['type'],
                            'file_type' => 'file',
                            'user_id' => $userId,
                        ]);

                        $mediaContent = MediaContent::create([
                            'media_id' => $media->id,
                            'file_id' => $result['fileId'],
                            'content_type' => $result['type'],
                            'order' => 0,
                            'image_url' => $result['type'] === 'image' ? $result['directUrl'] : null,
                            'video_url' => $result['type'] === 'video' ? $result['directUrl'] : null,
                            'music_url' => $result['type'] === 'audio' ? $result['directUrl'] : null,
                            'file_path' => $result['filePath'],
                        ]);

                        Log::info('Медиа успешно сохранено', [
                            'media_id' => $media->id,
                            'media_content_id' => $mediaContent->id,
                            'type' => $result['type'],
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
     * Получение временной ссылки на файл с кэшированием.
     *
     * @param string $filePath
     * @return string
     * @throws \Exception
     */
    private function getYandexDirectLink($filePath)
    {
        $cacheKey = 'yandex_download_url_' . md5($filePath);
        $cachedUrl = Cache::get($cacheKey);

        if ($cachedUrl) {
            return $cachedUrl;
        }

        try {
            $client = new Client();
            $downloadLinkResponse = $client->get("https://cloud-api.yandex.net/v1/disk/resources/download", [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);

            $downloadData = json_decode($downloadLinkResponse->getBody(), true);
            $directUrl = $downloadData['href'] ?? null;

            if (!$directUrl || !preg_match('/^https:\/\/downloader\.disk\.yandex\.ru\/disk\//', $directUrl)) {
                Log::error('Не удалось получить корректную временную ссылку', [
                    'filePath' => $filePath,
                    'downloadData' => $downloadData,
                ]);
                throw new \Exception('Не удалось получить временную ссылку');
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
            Log::error('Общая ошибка получения временной ссылки', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Обновление временной ссылки на медиафайл.
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
                Log::error('Путь к файлу отсутствует в MediaContent', ['media_id' => $mediaId]);
                return response()->json(['message' => 'Путь к файлу не найден'], 400);
            }

            // Проверяем существование файла на Яндекс.Диске
            if (!$this->checkFileExistsOnYandex($filePath)) {
                Log::error('Файл не найден на Яндекс.Диске', ['media_id' => $mediaId, 'path' => $filePath]);
                return response()->json(['message' => 'Файл не найден на Яндекс.Диске'], 404);
            }

            // Получаем новую временную ссылку
            $newUrl = $this->getYandexDirectLink($filePath);

            // Проверяем формат URL
            if (!preg_match('/^https:\/\/downloader\.disk\.yandex\.ru\/disk\//', $newUrl)) {
                Log::error('Некорректный формат обновлённой ссылки', [
                    'media_id' => $mediaId,
                    'newUrl' => $newUrl,
                ]);
                return response()->json(['message' => 'Некорректный формат временной ссылки'], 500);
            }

            // Обновляем ссылку в базе данных
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
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('Ошибка HTTP-запроса к Яндекс.Диску', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка API Яндекс.Диска: ' . $e->getMessage()], 503);
        } catch (\Exception $e) {
            Log::error('Общая ошибка обновления ссылки', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ошибка сервера: ' . $e->getMessage()], 500);
        }
    }
}
