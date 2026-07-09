<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentComment extends Model
{
    use HasFactory;

    protected $table = 'content_comments';

    protected $fillable = [
        'content_id',
        'user_id',
        'komentar',
        'image_path',
    ];

    public function content()
    {
        return $this->belongsTo(ClassroomContent::class, 'content_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
