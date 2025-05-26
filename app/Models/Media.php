<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = ['mediable_id', 'mediable_type', 'type', 'file_type'];

    public function mediable()
    {
        return $this->morphTo();
    }

    public function content()
    {
        return $this->hasOne(MediaContent::class, 'media_id', 'id');
    }
}
