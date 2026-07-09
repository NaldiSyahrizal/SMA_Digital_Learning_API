<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = ['kode_mapel', 'nama', 'kategori', 'tingkatan_id', 'jam_pelajaran'];

    public function packages()
    {
        return $this->belongsToMany(Package::class, 'package_subjects');
    }

    public function tingkatan()
    {
        return $this->belongsTo(Tingkatan::class, 'tingkatan_id');
    }

    public function interests()
    {
        return $this->hasMany(StudentSubjectInterest::class, 'subject_id');
    }
}
