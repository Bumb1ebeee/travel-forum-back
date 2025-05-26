<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        try {
            $type = $request->query('type');
            $validTypes = ['discussions', 'users', 'replies'];

            if (!in_array($type, $validTypes)) {
                return response()->json(['message' => 'Некорректный тип жалобы'], 400);
            }

            // Маппинг типа на модель
            $typeMap = [
                'discussions' => 'App\Models\Discussion',
                'users' => 'App\Models\User',
                'replies' => 'App\Models\Reply',
            ];

            // Загружаем только жалобы со статусом 'pending'
            $reports = Report::where('reportable_type', $typeMap[$type])
                ->where('status', 'pending')
                ->with([
                    'reporter:id,username',
                    'reportable' => function ($query) use ($type) {
                        if ($type === 'discussions') {
                            $query->select('id', 'title', 'user_id', 'category_id')
                                ->with(['user:id,username', 'category:id,name']);
                        } elseif ($type === 'users') {
                            $query->select('id', 'username', 'email');
                        } elseif ($type === 'replies') {
                            $query->select('id', 'content', 'user_id', 'discussion_id')
                                ->with(['user:id,username', 'discussion:id,title']);
                        }
                    }
                ])
                ->get();

            Log::info('Жалобы получены', ['type' => $type, 'count' => $reports->count()]);

            return response()->json(['reports' => $reports]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения жалоб', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'type' => $request->query('type'),
            ]);
            return response()->json(['message' => 'Ошибка получения жалоб: ' . $e->getMessage()], 500);
        }
    }

    public function moderate(Request $request, $reportId)
    {
        try {
            $request->validate([
                'status' => 'required|in:approved,rejected',
                'comment' => 'nullable|string|max:1000',
            ]);

            $report = Report::findOrFail($reportId);

            $user = Auth::guard('sanctum')->user();
            if (!$user || $user->role !== 'moderator') {
                return response()->json(['message' => 'Доступ запрещён'], 403);
            }

            $report->update([
                'status' => $request->status,
                'moderator_comment' => $request->comment,
                'moderator_id' => $user->id, // Добавляем moderator_id
            ]);

            if ($request->status === 'approved') {
                if ($report->reportable_type === 'App\Models\Discussion') {
                    $report->reportable->delete();
                } elseif ($report->reportable_type === 'App\Models\Reply') {
                    $report->reportable->delete();
                } elseif ($report->reportable_type === 'App\Models\User') {
                    $report->reportable->update(['status' => 'banned']);
                }
            }

            Log::info('Жалоба промодерирована', [
                'report_id' => $reportId,
                'status' => $request->status,
                'moderator_id' => $user->id,
                'comment' => $request->comment,
            ]);

            return response()->json(['message' => 'Жалоба обработана']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Ошибка валидации при модерации жалобы', ['errors' => $e->errors()]);
            return response()->json(['message' => 'Ошибка валидации', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Ошибка модерации жалобы', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'report_id' => $reportId,
            ]);
            return response()->json(['message' => 'Ошибка модерации жалобы: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Проверка аутентификации
            if (!Auth::check()) {
                Log::warning('Попытка создания отчета без аутентификации', [
                    'request_data' => $request->all(),
                ]);
                return response()->json(['message' => 'Неавторизован'], 401);
            }

            // Валидация входных данных
            $validated = $request->validate([
                'reason' => 'required|string|max:255',
                'reportable_id' => 'required|integer',
                'reportable_type' => 'required|in:App\\Models\\Discussion,App\\Models\\Reply',
            ]);

            // Логирование входных данных для отладки
            Log::info('Создание отчета', [
                'user_id' => Auth::id(),
                'validated_data' => $validated,
            ]);

            // Создание отчета
            $report = Report::create([
                'reason' => $validated['reason'],
                'reportable_id' => $validated['reportable_id'],
                'reportable_type' => $validated['reportable_type'],
                'reporter_id' => Auth::id(),
                'status' => 'pending',
            ]);

            return response()->json(['report' => $report, 'message' => 'Отчет успешно отправлен'], 201);
        } catch (\Exception $e) {
            Log::error('Ошибка создания отчета', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ошибка создания отчета: ' . $e->getMessage()], 500);
        }
    }

}
