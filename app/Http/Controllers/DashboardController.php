<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Classroom;
use App\Models\Subject;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $totalGuru = \App\Models\TeacherProfile::count();
        $totalSiswa = \App\Models\StudentProfile::count();
        $totalKelas = Classroom::count();
        $totalMapel = Subject::count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_guru' => $totalGuru,
                'total_siswa' => $totalSiswa,
                'total_kelas' => $totalKelas,
                'total_mapel' => $totalMapel,
            ]
        ]);
    }
}
