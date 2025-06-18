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

            $typeMap = [
                'discussions' => 'App\Models\Discussion',
                'users' => 'App\Models\User',
                'replies' => 'App\Models\Reply',
            ];

            $groupedReports = Report::where('reportable_type', $typeMap[$type])
                ->where('status', 'pending')
                ->with([
                    'reporter:id,username',
                    'reportable'
                ])
                ->get()
                ->groupBy(function ($item) {
                    return $item->reportable_type . '|' . $item->reportable_id;
                });

            $formattedReports = [];

            foreach ($groupedReports as $key => $reports) {
                [$reportableType, $reportableId] = explode('|', $key);
                $reports = $groupedReports[$key];
                $reportable = $reports->first()->reportable;

                $formattedReports[] = [
                    'reportable_type' => $reportableType,
                    'reportable_id' => $reportableId,
                    'reportable' => $reportable,
                    'total_reports' => $reports->count(),
                    'reasons' => $reports->pluck('reason')->toArray(),
                    'reporters' => $reports->map(function ($r) {
                        return [
                            'id' => $r->reporter->id,
                            'username' => $r->reporter->username,
                        ];
                    }),
                ];
            }

        return response()->json(['groups' => $formattedReports]);
    } catch (\Exception $e) {
            return response()->json(['message' => 'Ошибка получения жалоб: ' . $e->getMessage()], 500);
        }
    }

    public function moderateGroup(Request $request)
    {
        try {
            $request->validate([
                'reportable_id' => 'required|integer',
                'status' => 'required|in:approved,rejected',
                'comment' => 'nullable|string|max:1000',
            ]);

            // Найдём все нерассмотренные жалобы на этот объект
            $reports = Report::where('reportable_id', $request->reportable_id)
                ->where('status', 'pending')
                ->get();

            foreach ($reports as $report) {
                $report->update([
                    'status' => $request->status,
                    'moderator_comment' => $request->comment,
                    'moderator_id' => Auth::id(),
                ]);

                // Удаление объекта, если жалобу одобрили
                if ($request->status === 'approved') {
                    $reportable = $report->reportable;
                    if ($reportable) {
                        if ($reportable instanceof \App\Models\User) {
                            $reportable->update(['status' => 'banned']);
                        } else {
                            $reportable->delete();
                        }
                    }
                }
            }

            return response()->json(['message' => 'Группа жалоб промодерирована']);
        } catch (\Exception $e) {
            Log::error('Ошибка групповой модерации', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);
            return response()->json(['message' => 'Ошибка групповой модерации'], 500);
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

            if (!$user) {
                return response()->json(['message' => 'Доступ запрещён'], 403);
            }

            // Проверка прав модерации
                if ($report->reportable_type === 'App\Models\Reply') {
                    $reply = $report->reportable;
                    if (!$reply || $reply->discussion?->user_id !== $user->id) {
                        return response()->json(['message' => 'Доступ запрещён'], 403);
                    }
                } else {
                    return response()->json(['message' => 'Доступ запрещён'], 403);
                }

            // Обновляем статус жалобы
            $report->update([
                'status' => $request->status,
                'moderator_comment' => $request->comment,
                'moderator_id' => $user->id,
            ]);

            // Получаем объект, на который подана жалоба
            $reportable = $report->reportable;

            if ($request->status === 'approved') {
                if ($report->reportable_type === 'App\Models\Discussion') {
                    $reportable->delete();
                } elseif ($report->reportable_type === 'App\Models\Reply') {
                    $reportable->delete();
                } elseif ($report->reportable_type === 'App\Models\User') {
                    $reportable->update(['status' => 'banned']);
                }
            }

            // Считаем общее число жалоб на этот объект
            $totalReports = Report::where('reportable_type', $report->reportable_type)
                ->where('reportable_id', $report->reportable_id)
                ->where('status', 'pending')
                ->count();

            // Если жалоб больше 5 — блокируем автора на N дней
            $threshold = 5;
            $banDays = 7;

            if ($totalReports >= $threshold) {
                $author = null;

                if ($report->reportable_type === 'App\Models\Reply') {
                    $author = $reportable->user;
                } elseif ($report->reportable_type === 'App\Models\Discussion') {
                    $author = $reportable->user;
                }

                if ($author) {
                    $author->update([
                        'blocked_until' => now()->addDays($banDays),
                        'is_blocked' => true,
                    ]);

                    Log::info("Пользователь заблокирован за {$totalReports} жалоб", [
                        'user_id' => $author->id,
                        'blocked_until' => now()->addDays($banDays),
                    ]);
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
            if (!Auth::check()) {
                return response()->json(['message' => 'Неавторизован'], 401);
            }

            $validated = $request->validate([
                'reason' => 'required|string|max:255',
                'reportable_id' => 'required|integer',
                'reportable_type' => 'required|in:App\\Models\\Discussion,App\\Models\\Reply,App\\Models\\User',
            ]);

            // Проверяем, нет ли уже существующей жалобы от этого пользователя на тот же объект
            $existingReport = Report::where('reporter_id', Auth::id())
                ->where('reportable_id', $validated['reportable_id'])
                ->where('reportable_type', $validated['reportable_type'])
                ->whereIn('status', ['pending', 'approved', 'rejected']) // можно ограничить только pending
                ->exists();

            if ($existingReport) {
                return response()->json(['message' => 'Вы уже отправили жалобу на этот объект'], 400);
            }

            $report = Report::create([
                'reason' => $validated['reason'],
                'reportable_id' => $validated['reportable_id'],
                'reportable_type' => $validated['reportable_type'],
                'reporter_id' => Auth::id(),
                'status' => 'pending',
            ]);

            return response()->json(['report' => $report, 'message' => 'Жалоба успешно отправлена'], 201);
        } catch (\Exception $e) {
            Log::error('Ошибка создания жалобы', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            return response()->json(['message' => 'Ошибка при отправке жалобы'], 500);
        }
    }

     public function myResponseReports(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Получаем жалобы на модели типа Reply, которые находятся в обсуждениях,
        // принадлежащих текущему пользователю
        $reports = Report::where('reportable_type', 'App\Models\Reply') // Жалобы именно на ответы
        ->whereHas('reportable', function ($query) use ($user) {
            // reportable — это Reply, и мы проверяем его обсуждение (discussion)
            $query->whereHas('discussion', function ($q) use ($user) {
                $q->where('user_id', $user->id); // обсуждение принадлежит пользователю
            });
        })
            ->where('status', 'pending') // только нерассмотренные
            ->with([
                'reporter:id,name',
                'reportable' => function ($query) {
                    // Загружаем поля из Reply
                    $query->select('id', 'content', 'discussion_id', 'user_id');
                },
                'reportable.discussion:id,title,user_id' // опционально: загрузка данных обсуждения
            ])
            ->latest()
            ->paginate(10);

        \Log::info('myResponseReports fetched', [
            'user_id' => $user->id,
            'report_count' => $reports->total(),
            'first_report' => $reports->first() ? [
                'reportable_type' => $reports->first()->reportable_type,
                'reportable_id' => $reports->first()->reportable_id,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $reports->items(),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'has_more_pages' => $reports->hasMorePages(),
            ]
        ]);
    }
}
