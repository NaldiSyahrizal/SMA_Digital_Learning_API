<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentClassroom;
use App\Models\StudentProfile;
use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentClassroomController extends Controller
{
    public function index(Request $request)
    {
        $query = StudentClassroom::with(['student.user', 'classroom']);

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        $results = $query->get();

        $plotings = $results->map(function ($ploting) {
            if (!$ploting->student) {
                return null;
            }
            return [
                'id' => $ploting->id,
                'student_id' => $ploting->student_id,
                'class_id' => $ploting->class_id,
                'student' => [
                    'id' => $ploting->student->id,
                    'nis' => $ploting->student->nis,
                    'nama_lengkap' => $ploting->student->nama_lengkap,
                    'username' => $ploting->student->user->username ?? '-',
                    'email' => $ploting->student->user->email ?? '-',
                ],
                'classroom' => $ploting->classroom
            ];
        })->filter()->values();

        return response()->json($plotings);
    }

    public function store(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classrooms,id',
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:student_profiles,id',
        ]);

        $class_id = $request->class_id;
        $student_ids = $request->student_ids;

        DB::beginTransaction();
        try {
            foreach ($student_ids as $student_id) {
                // Remove from any existing class first
                StudentClassroom::where('student_id', $student_id)->delete();
                StudentClassroom::create([
                    'student_id' => $student_id,
                    'class_id' => $class_id,
                ]);
            }
            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Berhasil memploting ' . count($student_ids) . ' siswa ke kelas']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate(['class_id' => 'required|exists:classrooms,id']);
        $ploting = StudentClassroom::findOrFail($id);
        $ploting->update(['class_id' => $request->class_id]);
        return response()->json(['status' => 'success', 'message' => 'Berhasil memperbarui kelas siswa']);
    }

    public function destroy($id)
    {
        $ploting = StudentClassroom::findOrFail($id);
        $ploting->delete();
        return response()->json(['status' => 'success', 'message' => 'Siswa berhasil dikeluarkan dari kelas']);
    }

    // Returns students who are not yet assigned to any class
    public function unassignedStudents()
    {
        $students = StudentProfile::with('user')
            ->whereDoesntHave('classroom')
            ->get()
            ->map(function ($profile) {
                return [
                    'id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'username' => $profile->user->username ?? '-',
                    'email' => $profile->user->email ?? '-',
                    'nis' => $profile->nis,
                    'nama_lengkap' => $profile->nama_lengkap,
                    'jenis_kelamin' => $profile->jenis_kelamin,
                    'no_telp' => $profile->no_telp,
                    'foto_profile' => $profile->foto_profile,
                ];
            });

        return response()->json($students);
    }

    // Debug endpoint - check database state
    public function debug()
    {
        return response()->json([
            'total_students' => StudentProfile::count(),
            'total_classrooms' => Classroom::count(),
            'total_plotings' => StudentClassroom::count(),
            'unassigned_students' => StudentProfile::whereDoesntHave('classroom')->count(),
            'sample_students' => StudentProfile::limit(3)->get(['id', 'nis', 'nama_lengkap']),
            'sample_plotings' => StudentClassroom::limit(3)->get(),
        ]);
    }
}
