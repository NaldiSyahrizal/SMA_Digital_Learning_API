<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nis',
        'nama_lengkap',
        'jenis_kelamin',
        'no_telp',
        'foto_profile',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function classroom()
    {
        return $this->hasOne(StudentClassroom::class, 'student_id');
    }

    public function studentClassrooms()
    {
        return $this->hasMany(StudentClassroom::class, 'student_id');
    }

    public function interests()
    {
        return $this->hasMany(StudentSubjectInterest::class, 'student_id');
    }
}
