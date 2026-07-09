<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Tingkatan;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_kelas',
        'tingkatan_id',
        'wali_kelas_id',
        'package_id',
    ];

    public function waliKelas()
    {
        return $this->belongsTo(User::class, 'wali_kelas_id');
    }

    public function tingkatan()
    {
        return $this->belongsTo(Tingkatan::class, 'tingkatan_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function studentClassrooms()
    {
        return $this->hasMany(StudentClassroom::class, 'class_id');
    }
}
