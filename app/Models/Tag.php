<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status'];

    public function discussions()
    {
        return $this->belongsToMany(Discussion::class, 'discussion_tag');
    }
}
