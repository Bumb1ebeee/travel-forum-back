<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $user = $request->user();
        $file = $request->file('avatar');

        // Сохраняем файл напрямую
        $path = $file->store('avatars', 'public');

        // Получаем публичный URL
        $url = Storage::disk('public')->url($path);

        // Обновляем путь к аватару
        $user->avatar = $url;
        $user->save();

        return response()->json(['avatar' => $user->avatar], 200);
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
}
