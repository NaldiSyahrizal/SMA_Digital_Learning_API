<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['nama_paket', 'jurusan', 'tingkatan_id', 'deskripsi'];

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'package_subjects');
    }

    public function tingkatan()
    {
        return $this->belongsTo(Tingkatan::class, 'tingkatan_id');
    }
}
