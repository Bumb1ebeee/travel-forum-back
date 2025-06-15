<?php

namespace App\Http\Controllers;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;

class UserController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($validated);

        return response()->json(['user' => $user], 200);
    }

    private $yandexToken;

    public function __construct()
    {
        $this->yandexToken = env('YANDEX_DISK_TOKEN');
        if (!$this->yandexToken) {
            \Log::error('Yandex Disk token не найден в .env');
            throw new \Exception('Yandex Disk token не найден в .env');
        }
    }

    /**
     * Загрузка файла на Яндекс.Диск и получение прямой ссылки
     */

    private function uploadToYandexDisk($file)
    {
        $client = new Client();
        $fileName = uniqid() . '_' . $file->getClientOriginalName();
        $filePath = "/avatars/{$fileName}";

        try {
            // Получаем ссылку для загрузки
            $response = $client->get("https://cloud-api.yandex.net/v1/disk/resources/upload",  [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath, 'overwrite' => true],
            ]);

            $data = json_decode($response->getBody(), true);

            // Загружаем файл
            $client->put($data['href'], [
                'headers' => ['Content-Type' => $file->getClientMimeType()],
                'body' => fopen($file->getPathname(), 'rb'),
            ]);

            // Публикуем файл
            $client->put("https://cloud-api.yandex.net/v1/disk/resources/publish",  [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);

            // Получаем публичную ссылку
            $publicLinkResponse = $client->get("https://cloud-api.yandex.net/v1/disk/resources",  [
                'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                'query' => ['path' => $filePath],
            ]);

            $publicData = json_decode($publicLinkResponse->getBody(), true);
            return $publicData['file'] ?? null;

        } catch (\Exception $e) {
            \Log::error('Ошибка загрузки аватара на Яндекс.Диск', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Создание папки на Яндекс.Диске, если её нет
     */
    private function createYandexDiskFolder($path)
    {
        try {
            $client = new Client();

            // Проверяем, существует ли папка
            try {
                $client->get("https://cloud-api.yandex.net/v1/disk/resources", [
                    'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                    'query' => ['path' => $path],
                ]);
                return true; // Папка уже есть
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() !== 404) {
                    throw $e;
                }
                // Если 404 — создаём папку
                $client->put("https://cloud-api.yandex.net/v1/disk/resources", [
                    'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                    'query' => ['path' => $path],
                ]);
            }

            return true;

        } catch (\Exception $e) {
            \Log::error('Ошибка создания папки на Яндекс.Диске', [
                'error' => $e->getMessage(),
                'path' => $path,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Обновление аватара пользователя
     */
    public function updateAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $user = $request->user();
            $file = $request->file('avatar');

            // Загружаем на Яндекс.Диск
            $directUrl = $this->uploadToYandexDisk($file);

            // Удаляем старый аватар с Яндекс.Диска
            if ($user->avatar && str_contains($user->avatar, 'yandex')) {
                $oldPath = parse_url($user->avatar, PHP_URL_PATH);
                try {
                    $client = new Client();
                    $client->delete("https://cloud-api.yandex.net/v1/disk/resources",  [
                        'headers' => ['Authorization' => "OAuth {$this->yandexToken}"],
                        'query' => ['path' => $oldPath],
                    ]);
                } catch (\Exception $e) {
                    \Log::warning('Не удалось удалить старый аватар', [
                        'path' => $oldPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Обновляем аватар в базе
            $user->avatar = $directUrl;
            $user->save();

            return response()->json(['avatar' => $user->avatar], 200);

        } catch (\Exception $e) {
            \Log::error('Ошибка обновления аватара:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Ошибка сервера'], 500);
        }
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Неверный текущий пароль'],
            ]);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Пароль успешно изменен'], 200);
    }

    public function updateNotifications(Request $request)
    {
        $request->validate([
            'email_notifications' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->email_notifications = $request->email_notifications;
        $user->save();

        return response()->json(['message' => 'Настройки уведомлений сохранены'], 200);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();
        $user->delete();

        return response()->json(['message' => 'Аккаунт удален'], 200);
    }

    public function index()
    {
        return response()->json([
            'users' => User::all()
        ]);
    }

    public function block($id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Пользователь не найден'], 404);

        $user->update([
            'is_blocked' => true,
            'blocked_until' => now()->addDays(7),
            ]);
        return response()->json(['message' => 'Пользователь заблокирован']);
    }

    public function unblock($id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Пользователь не найден'], 404);

        $user->update(['is_blocked' => false]);
        return response()->json(['message' => 'Пользователь разблокирован']);
    }
}
