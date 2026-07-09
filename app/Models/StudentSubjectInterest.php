<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentSubjectInterest extends Model
{
    use HasFactory;

    protected $table = 'student_subject_interests';

    protected $fillable = [
        'student_id',
        'subject_id',
        'interest_score',
    ];

    public function student()
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
