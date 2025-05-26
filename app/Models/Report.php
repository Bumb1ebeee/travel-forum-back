<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    protected $fillable = ['reason',
        'reportable_id',
        'reportable_type',
        'reporter_id',
        'status',
        'moderator_comment',
        'moderator_id'];


    /**
     * Полиморфная связь с моделью, на которую подана жалоба.
     */
    public function reportable()
    {
        return $this->morphTo();
    }

    /**
     * Связь с пользователем, подавшим жалобу.
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

}
