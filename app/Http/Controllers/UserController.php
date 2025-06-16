<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class UserController extends Controller
{
    private const BASE_PATH = 'avatars/';
    private const CACHE_TTL_HOURS = 12; // Срок жизни кэша для ссылок

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

    /**
     * Загрузка аватара через Yandex Cloud Storage
     */
    private function uploadToYandexCloud($file)
    {
        try {
            // Проверка MIME-типа
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
                throw new \Exception('Недопустимый тип файла');
            }

            // Генерация уникального имени
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $filePath = self::BASE_PATH . $fileName;

            // Загрузка файла
            $result = Storage::disk('s3')->put($filePath, file_get_contents($file), [
                'visibility' => 'public',
                'ContentType' => $mimeType,
            ]);

            if (!$result) {
                throw new \Exception('Не удалось загрузить файл');
            }

            // Проверяем, действительно ли файл существует
            if (!Storage::disk('s3')->exists($filePath)) {
                throw new \Exception('Файл не найден после загрузки');
            }

            // Получаем URL
            $url = Storage::disk('s3')->url($filePath) . '?disposition=inline';

            return $url;
        } catch (\Exception $e) {
            \Log::error('Ошибка загрузки аватара на Yandex Cloud', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Удаление старого аватара с Yandex Cloud Storage
     */
    private function deleteOldAvatarFromCloud(string $url): bool
    {
        try {
            $parsedUrl = parse_url($url);
            $path = ltrim(strtok($parsedUrl['path'], '/'), '/');
            if ($path && Storage::disk('s3')->exists($path)) {
                Storage::disk('s3')->delete($path);
            }
            return true;
        } catch (\Exception $e) {
            \Log::warning('Ошибка удаления аватара', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Обновление аватара пользователя
     */
    public function updateAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            $user = $request->user();
            $file = $request->file('avatar');

            // Загружаем новый аватар
            $directUrl = $this->uploadToYandexCloud($file);

            // Удаляем старый аватар
            if ($user->avatar && str_contains($user->avatar, 'yandexcloud')) {
                $this->deleteOldAvatarFromCloud($user->avatar);
            }

            // Обновляем аватар в БД
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

    /**
     * Обновление пароля
     */
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

    /**
     * Обновление уведомлений
     */
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

    /**
     * Удаление аккаунта
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();
        $user->delete();

        return response()->json(['message' => 'Аккаунт удален'], 200);
    }

    /**
     * Получение списка пользователей
     */
    public function index()
    {
        return response()->json(['users' => User::all()]);
    }

    /**
     * Блокировка пользователя
     */
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

    /**
     * Разблокировка пользователя
     */
    public function unblock($id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Пользователь не найден'], 404);

        $user->update(['is_blocked' => false]);

        return response()->json(['message' => 'Пользователь разблокирован']);
    }
}
