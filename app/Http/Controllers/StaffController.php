<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StaffController extends Controller
{
    public function index()
    {
        try {
            $staff = User::where('role', 'moderator')->get(['id', 'username', 'email', 'role']);
            return response()->json(['staff' => $staff], 200);
        } catch (\Exception $e) {
            Log::error('Ошибка получения списка сотрудников: ' . $e->getMessage());
            return response()->json(['message' => 'Не удалось загрузить список сотрудников'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'username' => 'required|string',
                'role' => 'required|in:moderator',
            ]);

            $user = User::where('username', $validated['username'])->first();

            if (!$user) {
                return response()->json(['message' => 'Пользователь с таким именем не найден'], 404);
            }

            if ($user->role === 'moderator') {
                return response()->json(['message' => 'Пользователь уже является модератором'], 400);
            }

            $user->update(['role' => 'moderator']);

            return response()->json(['message' => 'Сотрудник успешно добавлен'], 201);
        } catch (\Exception $e) {
            Log::error('Ошибка добавления сотрудника: ' . $e->getMessage());
            return response()->json(['message' => 'Не удалось добавить сотрудника'], 500);
        }
    }

    public function destroy($userId)
    {
        try {
            $user = User::findOrFail($userId);

            if ($user->role !== 'moderator') {
                return response()->json(['message' => 'Пользователь не является модератором'], 400);
            }

            $user->update(['role' => 'user']);

            return response()->json(['message' => 'Сотрудник успешно уволен'], 200);
        } catch (\Exception $e) {
            Log::error('Ошибка увольнения сотрудника: ' . $e->getMessage());
            return response()->json(['message' => 'Не удалось уволить сотрудника'], 500);
        }
    }
}
