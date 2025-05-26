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
        return $this->hasMany(Subscription::class, 'subscriber_id');
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
