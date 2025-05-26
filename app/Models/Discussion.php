<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use HTMLPurifier;
use HTMLPurifier_Config;

class Discussion extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'category_id', 'user_id', 'is_draft', 'published_at',  'map', 'map_start', 'map_end', 'status', 'moderator_comment', 'is_archived'];

    public function setDescriptionAttribute($value)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,ul,ol,li,strong,em,b,i,img[src|alt],br');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^http://localhost:8000/storage/editor/images/.*%');
        $purifier = new HTMLPurifier($config);
        $this->attributes['description'] = $purifier->purify($value);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replies()
    {
        return $this->hasMany(Reply::class);
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'discussion_members', 'discussion_id', 'user_id')
            ->withTimestamps();
    }

    public function archivedByUsers()
    {
        return $this->belongsToMany(User::class, 'user_discussion_archives')->withTimestamps();
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'discussion_tag');
    }

    public function viewers()
    {
        return $this->belongsToMany(User::class, 'discussion_views')
            ->withTimestamps();
    }

    public function views()
    {
        return $this->hasMany(DiscussionView::class);
    }

    public function reports()
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function reactions()
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    public function getLikesAttribute()
    {
        return $this->reactions()->where('reaction', 'like')->count();
    }

    protected $casts = [
        'map' => 'array',
        'published_at' => 'datetime',
        'is_draft' => 'boolean',
    ];
}
