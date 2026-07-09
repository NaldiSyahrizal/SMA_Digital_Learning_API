<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Tingkatan;
use App\Models\User;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function index()
    {
        $classrooms = Classroom::with(['waliKelas.teacherProfile', 'tingkatan', 'package'])
            ->withCount('studentClassrooms as total_siswa')
            ->get()
            ->map(function ($classroom) {
                return [
                    'id'             => $classroom->id,
                    'nama_kelas'     => $classroom->nama_kelas,
                    'tingkatan_id'   => $classroom->tingkatan_id,
                    'tingkatan_name' => $classroom->tingkatan ? $classroom->tingkatan->nama_tingkat : '-',
                    'wali_kelas_id'  => $classroom->wali_kelas_id,
                    'wali_kelas_name'=> $classroom->waliKelas && $classroom->waliKelas->teacherProfile ? $classroom->waliKelas->teacherProfile->nama_lengkap : ($classroom->waliKelas ? $classroom->waliKelas->username : '-'),
                    'package_id'     => $classroom->package_id,
                    'package_name'   => $classroom->package ? $classroom->package->nama_paket : null,
                    'total_siswa'    => $classroom->total_siswa,
                ];
            });
        return response()->json($classrooms);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_kelas'    => 'required|string',
            'tingkatan_id'  => 'required|exists:tingkatans,id',
            'wali_kelas_id' => 'nullable|exists:users,id|unique:classrooms,wali_kelas_id',
            'package_id'    => 'nullable|exists:packages,id'
        ]);

        $classroom = Classroom::create($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Classroom created successfully',
            'data'    => $classroom
        ], 201);
    }

    public function show($id)
    {
        $classroom = Classroom::with(['waliKelas.teacherProfile', 'tingkatan', 'package'])->findOrFail($id);
        return response()->json([
            'id'             => $classroom->id,
            'nama_kelas'     => $classroom->nama_kelas,
            'tingkatan_id'   => $classroom->tingkatan_id,
            'tingkatan_name' => $classroom->tingkatan ? $classroom->tingkatan->nama_tingkat : '-',
            'wali_kelas_id'  => $classroom->wali_kelas_id,
            'wali_kelas_name'=> $classroom->waliKelas && $classroom->waliKelas->teacherProfile ? $classroom->waliKelas->teacherProfile->nama_lengkap : ($classroom->waliKelas ? $classroom->waliKelas->username : '-'),
            'package_id'     => $classroom->package_id,
            'package_name'   => $classroom->package ? $classroom->package->nama_paket : null,
        ]);
    }

    public function update(Request $request, $id)
    {
        $classroom = Classroom::findOrFail($id);

        $validated = $request->validate([
            'nama_kelas'    => 'sometimes|required|string',
            'tingkatan_id'  => 'sometimes|required|exists:tingkatans,id',
            'wali_kelas_id' => 'nullable|exists:users,id|unique:classrooms,wali_kelas_id,' . $classroom->id,
            'package_id'    => 'nullable|exists:packages,id'
        ]);

        $classroom->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Classroom updated successfully',
            'data'    => $classroom
        ]);
    }

    public function destroy($id)
    {
        $classroom = Classroom::findOrFail($id);
        $classroom->delete();
        return response()->json([
            'status'  => 'success',
            'message' => 'Classroom deleted successfully'
        ]);
    }

    public function getTeachers()
    {
        $teachers = User::whereHas('roles', function ($q) {
            $q->where('name', 'guru');
        })->get(['id', 'username as name']);

        return response()->json($teachers);
    }
}
