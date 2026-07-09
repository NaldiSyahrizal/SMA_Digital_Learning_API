<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeachingAssignment;
use Illuminate\Http\Request;

class TeacherClassController extends Controller
{
    /**
     * Get list of classrooms and subjects assigned to the authenticated teacher.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $profile = $user->teacherProfile;

        if (!$profile) {
            return response()->json([
                'status' => 'success',
                'data' => []
            ], 200);
        }

        // Fetch teaching assignments linked to this teacher
        $assignments = TeachingAssignment::where('teacher_id', $profile->id)
            ->with(['classroom', 'subject'])
            ->get()
            ->map(function ($item) {
                // Count total students in this classroom
                $totalStudents = $item->classroom ? $item->classroom->studentClassrooms()->count() : 0;
                
                return [
                    'id' => $item->id,
                    'class_id' => $item->class_id,
                    'class_name' => $item->classroom ? $item->classroom->nama_kelas : 'Tanpa Kelas',
                    'subject_id' => $item->subject_id,
                    'subject_name' => $item->subject ? $item->subject->nama : 'Tanpa Mapel',
                    'total_students' => $totalStudents,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $assignments
        ], 200);
    }

    /**
     * Get list of students enrolled in a specific class.
     */
    public function getStudents($classId)
    {
        $classroom = \App\Models\Classroom::findOrFail($classId);
        $studentClassrooms = $classroom->studentClassrooms()->with('student')->get();

        $students = $studentClassrooms->map(function ($sc) {
            $student = $sc->student;
            if (!$student) return null;
            return [
                'id' => $student->id,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => $students
        ], 200);
    }

    /**
     * Get dashboard statistics for the authenticated teacher.
     */
    public function getDashboardStats(Request $request)
    {
        $user = $request->user();
        $profile = $user->teacherProfile;

        if (!$profile) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya Guru yang dapat mengakses dashboard statistik ini.'
            ], 403);
        }

        // 1. Total Classes & Subjects taught
        $assignments = TeachingAssignment::where('teacher_id', $profile->id)->get();
        $classIds = $assignments->pluck('class_id')->unique();
        $subjectIds = $assignments->pluck('subject_id')->unique();
        
        $totalClasses = $classIds->count();
        $totalSubjects = $subjectIds->count();

        // 2. Unique Students across all taught classes
        $totalStudents = \App\Models\StudentClassroom::whereIn('class_id', $classIds)
            ->distinct('student_id')
            ->count('student_id');

        // 3. Contents created by this teacher
        $contentsQuery = \App\Models\ClassroomContent::where('teacher_id', $profile->id);
        
        $totalMaterials = (clone $contentsQuery)->where('tipe', 'materi')->count();
        $totalAssignments = (clone $contentsQuery)->where('tipe', 'tugas')->count();
        $totalQuizzes = (clone $contentsQuery)->where('tipe', 'kuis')->count();
        $totalContents = $totalMaterials + $totalAssignments + $totalQuizzes;

        // 4. Unread notifications count
        $unreadNotifications = \App\Models\Notification::where('teacher_id', $profile->id)
            ->where('is_read', false)
            ->count();

        // 5. Recent contents
        $recentContents = \App\Models\ClassroomContent::where('teacher_id', $profile->id)
            ->with(['classroom', 'subject'])
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'tipe' => $item->tipe,
                    'judul' => $item->judul,
                    'class_name' => $item->classroom ? $item->classroom->nama_kelas : 'Kelas',
                    'subject_name' => $item->subject ? $item->subject->nama : 'Mapel',
                    'created_at' => $item->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_classes' => $totalClasses,
                'total_subjects' => $totalSubjects,
                'total_students' => $totalStudents,
                'total_materials' => $totalMaterials,
                'total_assignments' => $totalAssignments,
                'total_quizzes' => $totalQuizzes,
                'total_contents' => $totalContents,
                'unread_notifications' => $unreadNotifications,
                'recent_contents' => $recentContents
            ]
        ], 200);
    }

    /**
     * Export all grades for a specific class and subject to PDF.
     */
    public function exportGrades($classId, $subjectId)
    {
        $classroom = \App\Models\Classroom::findOrFail($classId);
        $subject = \App\Models\Subject::findOrFail($subjectId);

        $contents = \App\Models\ClassroomContent::where('class_id', $classId)
            ->where('subject_id', $subjectId)
            ->whereIn('tipe', ['tugas', 'kuis'])
            ->orderBy('created_at', 'asc')
            ->get();

        $studentClassrooms = $classroom->studentClassrooms()->with('student')->get();
        
        $students = $studentClassrooms->map(function ($sc) {
            return $sc->student;
        })->filter()->sortBy('nama_lengkap')->values();

        // Siapkan struktur data nilai untuk dikirim ke view
        $studentScores = [];
        foreach ($students as $student) {
            $submissions = \App\Models\StudentSubmission::where('student_id', $student->id)
                ->whereIn('content_id', $contents->pluck('id'))
                ->get()
                ->groupBy('content_id');

            foreach ($contents as $content) {
                $subs = $submissions->get($content->id);
                if (!$subs || $subs->isEmpty()) {
                    $studentScores[$student->id][$content->id] = '0 (Belum Kumpul)';
                } else {
                    $latest = $subs->sortByDesc('attempt_number')->first();
                    if ($content->tipe == 'kuis') {
                        $score = $subs->max('nilai');
                        $studentScores[$student->id][$content->id] = $score !== null ? $score : 0;
                    } else {
                        if ($latest->status == 'graded') {
                            $studentScores[$student->id][$content->id] = $latest->nilai !== null ? $latest->nilai : 0;
                        } else {
                            $studentScores[$student->id][$content->id] = '0 (Belum Dinilai)';
                        }
                    }
                }
            }
        }

        $fileName = "Gradebook_" . preg_replace('/[^A-Za-z0-9\-]/', '_', $classroom->nama_kelas) . "_" . preg_replace('/[^A-Za-z0-9\-]/', '_', $subject->nama) . ".pdf";

        // Generate PDF
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf_gradebook', compact('classroom', 'subject', 'contents', 'students', 'studentScores'))
            ->setPaper('a4', 'landscape');
        
        return $pdf->download($fileName);
    }
}
