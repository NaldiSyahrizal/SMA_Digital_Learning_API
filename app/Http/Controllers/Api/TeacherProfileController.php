<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TeacherProfileController extends Controller
{
    /**
     * Get the authenticated teacher's profile.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        
        // Validate if user has teacher-related role
        $isTeacher = $user->roles->contains(function ($role) {
            return in_array($role->name, ['teacher', 'guru', 'pengajar']);
        });

        if (!$isTeacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Anda bukan Guru.'
            ], 403);
        }

        // Retrieve teacherProfile relation
        $profile = $user->teacherProfile;

        if (!$profile) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profil guru tidak ditemukan.'
            ], 404);
        }

        // Return combined data to match Android data model (TeacherProfile)
        return response()->json([
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'nip' => $profile->nip,
            'nama_lengkap' => $profile->nama_lengkap,
            'jenis_kelamin' => $profile->jenis_kelamin,
            'no_telp' => $profile->no_telp,
            'foto_profile' => $profile->foto_profile ? url('storage/' . $profile->foto_profile) : null,
            'username' => $user->username,
            'email' => $user->email
        ], 200);
    }

    /**
     * Update the authenticated teacher's profile.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        
        // Validate if user has teacher-related role
        $isTeacher = $user->roles->contains(function ($role) {
            return in_array($role->name, ['teacher', 'guru', 'pengajar']);
        });

        if (!$isTeacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Anda bukan Guru.'
            ], 403);
        }

        $profile = $user->teacherProfile;

        if (!$profile) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profil guru tidak ditemukan.'
            ], 404);
        }

        // Validation rules
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|unique:users,username,' . $user->id,
            'no_telp' => 'required|string|min:10',
            'password' => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        // Use database transactions to ensure atomicity
        DB::beginTransaction();

        try {
            // Update users table credentials
            $user->username = $request->username;
            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }
            $user->save();

            // Update teacher_profiles table info
            $profile->no_telp = $request->no_telp;
            $profile->save();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Profil berhasil diperbarui'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui profil: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload and update the authenticated teacher's profile picture.
     */
    public function uploadPhoto(Request $request)
    {
        $user = $request->user();

        // Validate if user has teacher-related role
        $isTeacher = $user->roles->contains(function ($role) {
            return in_array($role->name, ['teacher', 'guru', 'pengajar']);
        });

        if (!$isTeacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Anda bukan Guru.'
            ], 403);
        }

        $profile = $user->teacherProfile;

        if (!$profile) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profil guru tidak ditemukan.'
            ], 404);
        }

        // Validate file input
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        if ($request->hasFile('photo')) {
            // Delete old photo if it exists locally
            if ($profile->foto_profile) {
                // Extract file path from full storage URL if it was saved as URL
                $oldPath = str_replace(url('storage/'), '', $profile->foto_profile);
                $oldPath = ltrim($oldPath, '/');
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }

            // Save new photo inside profiles directory under public storage disk
            $path = $request->file('photo')->store('profiles', 'public');
            $profile->foto_profile = $path;
            $profile->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Foto profil berhasil diperbarui',
                'foto_profile' => url('storage/' . $path)
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'File gambar tidak ditemukan.'
        ], 400);
    }
}
