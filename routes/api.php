<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PopularController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReplyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/test', function () {
    return response()->json(['message' => 'API работает']);
});

Route::middleware(['auth:sanctum', 'blocked'])->group(function () {
    Route::get('/check-token', [AuthController::class, 'checkToken']);
    Route::post('/update-avatar', [UserController::class, 'updateAvatar']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/user-update', [UserController::class, 'update']);
    Route::get('/discussions', [DiscussionController::class, 'index']);
    Route::post('/discussions', [DiscussionController::class, 'store']);
    Route::put('/discussions/{id}', [DiscussionController::class, 'update']);
    Route::post('/discussions/{id}/archive', [DiscussionController::class, 'archiveSubscription']);
    Route::post('/discussions/{discussionId}/unarchive', [DiscussionController::class, 'unarchiveSubscription']);
    Route::delete('/discussions/{discussionId}/unsubscribe', [DiscussionController::class, 'unsubscribe']);
    Route::delete('/discussions/{discussionId}/unsubscribe/archived', [DiscussionController::class, 'unsubscribeArchived']);
    Route::get('/discussions/archived', [DiscussionController::class, 'archived']);
    Route::post('/save-search-query', [PopularController::class, 'saveSearchQuery']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/discussions/{id}/status', [DiscussionController::class, 'updateStatus']);

    Route::post('/drafts', [DiscussionController::class, 'saveDraft']);
    Route::put('/drafts/{id}', [DiscussionController::class, 'updateDraft']);
    Route::get('/drafts', [DiscussionController::class, 'getDrafts']);
    Route::delete('/discussions/{id}', [DiscussionController::class, 'deleteDiscussion']);
    Route::put('/discussions/{id}/publish', [DiscussionController::class, 'publishDiscussion']);
    Route::put('/discussions/{id}/unpublish', [DiscussionController::class, 'unpublishDiscussion']);
    Route::post('/upload-image', [DiscussionController::class, 'uploadImage']);
    Route::get('/media', [MediaController::class, 'index']);
    Route::post('/media', [MediaController::class, 'store']);
    Route::patch('/media/{id}', [MediaController::class, 'update']);
    Route::delete('/media/{id}', [MediaController::class, 'destroy']);

    Route::get('/discussions/drafts', [DiscussionController::class, 'getUserDrafts']);
    Route::get('/discussions/published', [DiscussionController::class, 'getUserPublished']);
    Route::get('/discussions/pending', [DiscussionController::class, 'getUserPending']);
    Route::get('/discussions/rejected', [DiscussionController::class, 'getUserRejected']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::post('/reports/{reportId}/moderate', [ReportController::class, 'moderate']);
    Route::post('/reports', [ReportController::class, 'store']);


    Route::get('/subscriptions/users', [SubscriptionController::class, 'userSubscriptions']);
    Route::get('/subscriptions/discussions', [SubscriptionController::class, 'discussionSubscriptions']);
    Route::post('/subscriptions/{userId}', [SubscriptionController::class, 'subscribe']);
    Route::delete('/subscriptions/{userId}', [SubscriptionController::class, 'unsubscribe']);
    Route::get('/discussions/{discussionId}/subscription', [DiscussionController::class, 'checkSubscription']);

    Route::post('/change-password', [UserController::class, 'changePassword']);
    Route::post('/update-notifications', [UserController::class, 'updateNotifications']);
    Route::delete('/delete-account', [UserController::class, 'deleteAccount']);
    Route::post('/reactions', [ReactionController::class, 'store']);
    Route::get('/reactions', [ReactionController::class, 'show']);
    Route::delete('/reactions', [ReactionController::class, 'destroy']);
    Route::get('/discussions/{id}/likes', [DiscussionController::class, 'likes']);

    Route::get('/users/me/response-reports', [ReportController::class, 'myResponseReports']);



    Route::middleware('role:moderator')->group(function () {
        Route::get('/pending-discussions', [DiscussionController::class, 'pendingDiscussions']);
        Route::post('/discussions/{id}/moderate', [DiscussionController::class, 'moderate']);
        Route::post('/reports/group', [ReportController::class, 'moderateGroup']);
    });

    Route::middleware( 'role:admin')->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::delete('/staff/{userId}', [StaffController::class, 'destroy']);
        Route::get('/statistics/moderators', [StatisticsController::class, 'getModerators']);
        Route::post('/tests', [TestController::class, 'store']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users/{id}/block', [UserController::class, 'block']);
        Route::post('/users/{id}/unblock', [UserController::class, 'unblock']);
        Route::post('/staff/assign', [StaffController::class, 'assign']); // Назначить модератора
    });
    Route::get('/tests', [TestController::class, 'index']);
    Route::get('/tests/{test}', [TestController::class, 'show']);
    Route::post('/tests/{test}/submit', [TestController::class, 'submit']);
    Route::get('/achievements', [AchievementController::class, 'index']);

    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/statistics/likes', [StatisticsController::class, 'likes'])->name('statistics.likes');
    Route::get('/statistics/users', [StatisticsController::class, 'users'])->name('statistics.users');
    Route::get('/statistics/replies', [StatisticsController::class, 'replies'])->name('statistics.replies');
    Route::get('/statistics/reports', [StatisticsController::class, 'reports'])->name('statistics.reports');
    Route::get('/statistics/views', [StatisticsController::class, 'views'])->name('statistics.views');
    Route::get('/statistics/recent-actions', [StatisticsController::class, 'recentActions'])->name('statistics.recent-actions');
});

Route::middleware(['auth:sanctum', 'blocked'])->group(function () {
    Route::get('/2fa/setup', [AuthController::class, 'setup2FA']);
    Route::post('/2fa/enable', [AuthController::class, 'enable2FA']);
    Route::post('/2fa/disable', [AuthController::class, 'disable2FA']);
});
Route::post('/2fa/verify', [AuthController::class, 'verify2FA']);

Route::get('/discussions/{discussion}', [DiscussionController::class, 'show']);
Route::get('/popular-tags', [TagController::class, 'popularTags']);
Route::get('/tags', [TagController::class, 'index']);
Route::post('/tags', [TagController::class, 'store']);
Route::post('/discussions/{discussion}/tags', [TagController::class, 'attachTags']);
Route::get('/popular-discussions', [PopularController::class, 'popularDiscussions']);
Route::get('/discussions-by-tag/{tag}', [PopularController::class, 'discussionsByTag']);
Route::get('/search', [PopularController::class, 'search']);
Route::get('/autocomplete', [PopularController::class, 'autocomplete']);
Route::get('/categories', [CategoriesController::class, 'getCategories']);
Route::get('categories/{category}', [CategoriesController::class, 'discussionsByCategory']);
Route::get('/personalized-discussions', [PopularController::class, 'getPersonalizedDiscussions']);
Route::post('/discussions/{id}/join', [DiscussionController::class, 'join'])->middleware('auth:sanctum');
Route::post('/discussions/{id}/replies', [ReplyController::class, 'addReply'])->middleware('auth:sanctum');
Route::get('/users/{id}', [SubscriptionController::class, 'show']);
Route::get('/users/{id}/discussions', [SubscriptionController::class, 'userDiscussions']);

Route::get('/discussions/{id}/subscribers', [DiscussionController::class, 'getSubscribers']);

Route::get('/media/{mediaId}/refresh', [MediaController::class, 'getDirectUrl']);
Route::post('/media/{mediaId}/refresh', [MediaController::class, 'refreshUrl']);


Route::get('/reply/{replyId}/refresh', [ReplyController::class, 'getDirectUrl']);
Route::post('/reply/{replyId}/refresh', [ReplyController::class, 'refreshUrl']);
