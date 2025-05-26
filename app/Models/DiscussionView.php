<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscussionView extends Model
{
    protected $fillable = ['user_id', 'discussion_id', 'created_at', 'updated_at'];

    public function discussion()
    {
        return $this->belongsTo(Discussion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
