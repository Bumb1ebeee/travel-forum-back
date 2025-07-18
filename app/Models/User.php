<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'role',
        'password',
        'avatar',
        'is_blocked',
        'two_factor_secret',
        'two_factor_enabled'
    ];

    public function tests()
    {
        return $this->hasMany(Test::class, 'created_by');
    }

    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements');
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function replies()
    {
        return $this->hasMany(Reply::class);
    }

    public function subscriptions()
    {
        return $this->belongsToMany(User::class, 'subscriptions', 'subscriber_id', 'user_id')
            ->withTimestamps()
            ->withPivot('id'); // ✅ Теперь withPivot() доступен
    }

    // Пользователи, которые подписаны на текущего пользователя
    public function subscribers()
    {
        return $this->hasMany(Subscription::class, 'user_id');
    }

    public function subscribe_discussions()
    {
        return $this->belongsToMany(Discussion::class, 'discussion_members', 'user_id', 'discussion_id')
            ->withTimestamps();
    }

    public function archivedDiscussions()
    {
        return $this->belongsToMany(Discussion::class, 'user_discussion_archives')->withTimestamps();
    }

    public function searchQueries()
    {
        return $this->hasMany(SearchQuery::class);
    }

    public function viewedDiscussions()
    {
        return $this->belongsToMany(Discussion::class, 'discussion_views')
            ->withTimestamps();
    }

    public function discussions()
    {
        return $this->hasMany(Discussion::class);
    }

    public function reports()
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function reported()
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function responseReports()
    {
        return $this->hasManyThrough(
            Report::class,
            Reply::class,
            'user_id',     // Foreign key on responses table
            'reportable_id', // Foreign key on reports table
            'id',          // Local key on users table
            'id'           // Local key on responses table
        )->where('reports.reportable_type', 'App\Models\Response');
    }

    public function processedReports()
    {
        return $this->hasMany(Report::class, 'moderator_id')
            ->whereIn('status', ['approved', 'rejected'])
            ->where('updated_at', '>=', now()->subDays(30));
    }

    public function reviewedDiscussions()
    {
        return $this->hasMany(Discussion::class, 'moderator_id')
            ->whereIn('status', ['approved', 'rejected'])
            ->where('is_draft', false);
    }

    public function reviewedReplies()
    {
        return $this->hasMany(Reply::class, 'moderator_id');
    }

    public function isBlocked()
    {
        return $this->is_blocked && now()->lessThan($this->blocked_until);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
