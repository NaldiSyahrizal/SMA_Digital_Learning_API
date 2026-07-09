<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ClassroomContent;
use App\Models\StudentSubmission;
use App\Models\TeacherProfile;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;

class PrincipalController extends Controller
{
    public function dashboard(Request $request)
    {
        // 1. Total Tugas & Kuis
        $totalTugas = ClassroomContent::where('tipe', 'tugas')->count();
        $totalKuis = ClassroomContent::where('tipe', 'kuis')->count();

        // 2. Partisipasi Guru
        $totalTeachers = TeacherProfile::count();
        $activeTeachers = ClassroomContent::select('teacher_id')->distinct()->count();
        $teacherParticipation = [
            'active' => $activeTeachers,
            'total' => $totalTeachers
        ];

        // 3. Partisipasi Siswa
        $totalStudents = StudentProfile::count();
        $activeStudents = StudentSubmission::select('student_id')->distinct()->count();
        $studentParticipation = [
            'active' => $activeStudents,
            'total' => $totalStudents
        ];

        // 4. Minat Mapel (Berdasarkan Rata-rata Bintang/Interest Score)
        $subjectInterests = DB::table('student_subject_interests')
            ->join('subjects', 'student_subject_interests.subject_id', '=', 'subjects.id')
            ->select('subjects.nama as subject_name', DB::raw('ROUND(AVG(student_subject_interests.interest_score), 1) as avg_rating'), DB::raw('count(student_subject_interests.id) as voters'))
            ->groupBy('subjects.id', 'subjects.nama')
            ->orderBy('avg_rating', 'desc')
            ->get();

        // 5. Statistik Waktu (Tugas dan Kuis per Bulan Tahun Ini)
        $currentYear = date('Y');
        
        $tugasStatsRaw = ClassroomContent::where('tipe', 'tugas')
            ->whereYear('created_at', $currentYear)
            ->select(DB::raw('MONTH(created_at) as month'), DB::raw('count(*) as count'))
            ->groupBy('month')
            ->get();
            
        $kuisStatsRaw = ClassroomContent::where('tipe', 'kuis')
            ->whereYear('created_at', $currentYear)
            ->select(DB::raw('MONTH(created_at) as month'), DB::raw('count(*) as count'))
            ->groupBy('month')
            ->get();

        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        
        $tugasStats = [];
        $kuisStats = [];
        for ($i = 1; $i <= 12; $i++) {
            $tugasCount = $tugasStatsRaw->firstWhere('month', $i);
            $kuisCount = $kuisStatsRaw->firstWhere('month', $i);
            
            $tugasStats[] = [
                'label' => $months[$i - 1],
                'count' => $tugasCount ? $tugasCount->count : 0
            ];
            $kuisStats[] = [
                'label' => $months[$i - 1],
                'count' => $kuisCount ? $kuisCount->count : 0
            ];
        }

        return response()->json([
            'message' => 'Dashboard data retrieved successfully',
            'data' => [
                'total_tugas' => $totalTugas,
                'total_kuis' => $totalKuis,
                'teacher_participation' => $teacherParticipation,
                'student_participation' => $studentParticipation,
                'subject_interests' => $subjectInterests,
                'tugas_stats' => $tugasStats,
                'kuis_stats' => $kuisStats
            ]
        ], 200);
    }
}
