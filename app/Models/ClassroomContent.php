<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassroomContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'subject_id',
        'teacher_id',
        'group_id',
        'tipe',
        'judul',
        'deskripsi',
        'file_path',
        'due_date',
        'is_closed',
        'close_automatically',
        'difficulty',
        'weight',
        'estimated_duration',
        'quiz_duration_minutes',
        'quiz_max_attempts',
        'allowed_file_types',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'close_automatically' => 'boolean',
        'is_closed' => 'boolean',
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function teacher()
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_id');
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class, 'content_id');
    }

    public function submissions()
    {
        return $this->hasMany(StudentSubmission::class, 'content_id');
    }
}
