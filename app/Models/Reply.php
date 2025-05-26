<?php

namespace App\Models;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reply extends Model
{
    use HasFactory;

    protected $fillable = ['discussion_id', 'user_id', 'content', 'parent_id', 'media_url', 'created_at', 'updated_at'];

    public function setContentAttribute($value)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,ul,ol,li,strong,em,b,i,img[src|alt],br');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('URI.SafeIframeRegexp', '%^http://localhost:8000/storage/editor/images/.*%');
        $purifier = new HTMLPurifier($config);
        $this->attributes['content'] = $purifier->purify($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function children()
    {
        return $this->hasMany(Reply::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(Reply::class, 'parent_id');
    }

    public function discussion()
    {
        return $this->belongsTo(Discussion::class);
    }

    public function reactions()
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function getLikesAttribute()
    {
        return $this->reactions()->where('reaction', 'like')->count();
    }
}
