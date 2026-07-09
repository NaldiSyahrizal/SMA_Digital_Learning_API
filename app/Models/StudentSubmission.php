<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_id',
        'student_id',
        'submission_text',
        'quiz_answers',
        'file_path',
        'nilai',
        'catatan',
        'status',
        'attempt_number',
        'exit_count',
        'exit_logs',
    ];

    protected $casts = [
        'exit_logs' => 'array',
    ];

    public function content()
    {
        return $this->belongsTo(ClassroomContent::class, 'content_id');
    }

    public function student()
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }
}
