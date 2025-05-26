<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaContent extends Model
{
    use HasFactory;

    protected $table = 'media_content';

    protected $fillable = [
        'media_id',
        'content_type',
        'text_content',
        'image_url',
        'video_url',
        'music_url',
        'map_points',
        'file_id',
        'order',
    ];

    protected $casts = [
        'map_points' => 'array',
    ];

    public function media()
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }
}
