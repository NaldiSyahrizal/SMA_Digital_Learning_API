<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TeacherController extends Controller
{
    public function index()
    {
        $teachers = TeacherProfile::with('user')->get()->map(function ($profile) {
            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'username' => $profile->user->username,
                'email' => $profile->user->email,
                'nip' => $profile->nip,
                'nama_lengkap' => $profile->nama_lengkap,
                'jenis_kelamin' => $profile->jenis_kelamin,
                'no_telp' => $profile->no_telp,
                'foto_profile' => $profile->foto_profile,
            ];
        });
        return response()->json($teachers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'nip' => 'nullable|string|unique:teacher_profiles',
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

            // Attach 'guru' role
            $role = Role::where('name', 'guru')->first();
            if ($role) {
                $user->roles()->attach($role->id);
            }

            $profile = TeacherProfile::create([
                'user_id' => $user->id,
                'nip' => $validated['nip'],
                'nama_lengkap' => $validated['nama_lengkap'],
                'jenis_kelamin' => $validated['jenis_kelamin'],
                'no_telp' => $validated['no_telp'] ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Teacher created successfully',
                'data' => $profile
            ], 201);
        });
    }

    public function show($id)
    {
        $profile = TeacherProfile::with('user')->findOrFail($id);
        return response()->json([
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'username' => $profile->user->username,
            'email' => $profile->user->email,
            'nip' => $profile->nip,
            'nama_lengkap' => $profile->nama_lengkap,
            'jenis_kelamin' => $profile->jenis_kelamin,
            'no_telp' => $profile->no_telp,
            'foto_profile' => $profile->foto_profile,
        ]);
    }

    public function update(Request $request, $id)
    {
        $profile = TeacherProfile::findOrFail($id);
        $user = $profile->user;

        $validated = $request->validate([
            'username' => 'sometimes|required|string|unique:users,username,' . $user->id,
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'nip' => 'nullable|string|unique:teacher_profiles,nip,' . $profile->id,
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
                'nip' => $validated['nip'] ?? $profile->nip,
                'nama_lengkap' => $validated['nama_lengkap'] ?? $profile->nama_lengkap,
                'jenis_kelamin' => $validated['jenis_kelamin'] ?? $profile->jenis_kelamin,
                'no_telp' => $validated['no_telp'] ?? $profile->no_telp,
            ]);

            // Create notification for teacher profile update
            \App\Models\Notification::create([
                'teacher_id' => $profile->id,
                'type' => 'profile_update',
                'message' => 'Profil akun Anda telah diperbarui oleh Admin. Silakan periksa tab Profil Anda.',
                'data' => null,
                'is_read' => false
            ]);

            // Optional FCM Push Notification
            if ($user && $user->device_token) {
                try {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: key=' . env('FCM_SERVER_KEY', 'MOCK_SERVER_KEY'),
                        'Content-Type: application/json',
                    ]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'to' => $user->device_token,
                        'notification' => [
                            'title' => '🔒 Keamanan & Akun',
                            'body' => 'Profil akun Anda telah diperbarui oleh Admin.',
                            'sound' => 'default',
                            'badge' => '1',
                        ],
                        'data' => [
                            'type' => 'profile_update'
                        ]
                    ]));
                    curl_exec($ch);
                    curl_close($ch);
                } catch (\Exception $e) {
                    // Fail silently
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Teacher updated successfully',
                'data' => $profile
            ]);
        });
    }

    public function destroy($id)
    {
        $profile = TeacherProfile::findOrFail($id);
        $user = $profile->user;
        
        DB::transaction(function () use ($user, $profile) {
            $profile->delete();
            $user->delete(); // Cascading delete would also work but let's be explicit
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Teacher deleted successfully'
        ]);
    }
}
