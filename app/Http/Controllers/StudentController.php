<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
    public function index()
    {
        $students = StudentProfile::with('user')->get()->map(function ($profile) {
            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'username' => $profile->user?->username,
                'email' => $profile->user?->email,
                'is_active' => (bool)$profile->user?->is_active,
                'nis' => $profile->nis,
                'nama_lengkap' => $profile->nama_lengkap,
                'jenis_kelamin' => $profile->jenis_kelamin,
                'no_telp' => $profile->no_telp,
                'foto_profile' => $profile->foto_profile,
            ];
        });
        return response()->json($students);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'nis' => 'required|string|unique:student_profiles',
            'nama_lengkap' => 'required|string',
            'jenis_kelamin' => 'required|in:L,P',
            'no_telp' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Attach 'siswa' or 'murid' role
            $role = Role::where('name', 'siswa')->first();
            if (!$role) {
                $role = Role::where('name', 'murid')->first();
            }
            if ($role) {
                $user->roles()->attach($role->id);
            }

            $profile = StudentProfile::create([
                'user_id' => $user->id,
                'nis' => $validated['nis'],
                'nama_lengkap' => $validated['nama_lengkap'],
                'jenis_kelamin' => $validated['jenis_kelamin'],
                'no_telp' => $validated['no_telp'] ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Student created successfully',
                'data' => $profile
            ], 201);
        });
    }

    public function show($id)
    {
        $profile = StudentProfile::with('user')->findOrFail($id);
        return response()->json([
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'username' => $profile->user?->username,
            'email' => $profile->user?->email,
            'is_active' => (bool)$profile->user?->is_active,
            'nis' => $profile->nis,
            'nama_lengkap' => $profile->nama_lengkap,
            'jenis_kelamin' => $profile->jenis_kelamin,
            'no_telp' => $profile->no_telp,
            'foto_profile' => $profile->foto_profile,
        ]);
    }

    public function update(Request $request, $id)
    {
        $profile = StudentProfile::findOrFail($id);
        $user = $profile->user;

        $validated = $request->validate([
            'username' => 'sometimes|required|string|unique:users,username,' . $user->id,
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'nis' => 'sometimes|required|string|unique:student_profiles,nis,' . $profile->id,
            'nama_lengkap' => 'sometimes|required|string',
            'jenis_kelamin' => 'sometimes|required|in:L,P',
            'no_telp' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $user, $profile) {
            $userUpdate = [
                'username' => $validated['username'] ?? $user->username,
                'email' => $validated['email'] ?? $user->email,
            ];
            if (!empty($validated['password'])) {
                $userUpdate['password'] = Hash::make($validated['password']);
            }
            $user->update($userUpdate);

            $profile->update([
                'nis' => $validated['nis'] ?? $profile->nis,
                'nama_lengkap' => $validated['nama_lengkap'] ?? $profile->nama_lengkap,
                'jenis_kelamin' => $validated['jenis_kelamin'] ?? $profile->jenis_kelamin,
                'no_telp' => $validated['no_telp'] ?? $profile->no_telp,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Student updated successfully',
                'data' => $profile
            ]);
        });
    }

    public function destroy(Request $request, $id)
    {
        $profile = StudentProfile::findOrFail($id);
        $user = $profile->user;
        
        $reason = $request->input('deactivation_reason');
        
        DB::transaction(function () use ($user, $reason) {
            $user->update([
                'is_active' => false,
                'deactivation_reason' => $reason
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Siswa berhasil dinonaktifkan'
        ]);
    }
}
