<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ContentComment;
use App\Models\ClassroomContent;

class ContentCommentController extends Controller
{
    public function index($id)
    {
        $content = ClassroomContent::findOrFail($id);
        
        $comments = ContentComment::where('content_id', $content->id)
            ->with(['user.roles', 'user.teacherProfile', 'user.studentProfile'])
            ->orderBy('created_at', 'asc')
            ->get();

        $formattedComments = $comments->map(function ($comment) {
            $user = $comment->user;
            $role = $user->roles->first()->name ?? 'unknown';
            
            $nama = $user->username;
            $namaLengkap = null;
            $fotoProfile = null;
            
            if ($role === 'guru' && $user->teacherProfile) {
                $nama = $user->teacherProfile->nama_lengkap;
                $namaLengkap = $user->teacherProfile->nama_lengkap;
                $fotoProfile = $user->teacherProfile->foto_profile ? url('storage/' . $user->teacherProfile->foto_profile) : null;
            } else if ($role === 'siswa' && $user->studentProfile) {
                $nama = $user->studentProfile->nama_lengkap;
                $namaLengkap = $user->studentProfile->nama_lengkap;
                $fotoProfile = $user->studentProfile->foto_profile ? url('storage/' . $user->studentProfile->foto_profile) : null;
            } else if ($role === 'admin' || $role === 'kepala_sekolah') {
                $nama = $user->username;
                $namaLengkap = $user->username;
            }

            return [
                'id' => $comment->id,
                'komentar' => $comment->komentar,
                'image_path' => $comment->image_path ? url('storage/' . $comment->image_path) : null,
                'is_mine' => $comment->user_id === auth()->id(),
                'created_at' => $comment->created_at->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'nama' => $nama,
                    'nama_lengkap' => $namaLengkap,
                    'foto_profile' => $fotoProfile,
                    'role' => $role
                ]
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $formattedComments
        ]);
    }

    public function store(Request $request, $id)
    {
        $request->validate([
            'komentar' => 'required|string|max:1000',
            'image' => 'nullable|image|max:5120' // max 5MB
        ]);

        $content = ClassroomContent::findOrFail($id);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('comments', 'public');
        }

        $comment = ContentComment::create([
            'content_id' => $content->id,
            'user_id' => auth()->id(),
            'komentar' => $request->komentar,
            'image_path' => $imagePath
        ]);
        $comment->load(['user.roles', 'user.teacherProfile', 'user.studentProfile']);

        $user = $comment->user;
        $role = $user->roles->first()->name ?? 'unknown';
        
        $nama = $user->username;
        $namaLengkap = null;
        $fotoProfile = null;
        
        if ($role === 'guru' && $user->teacherProfile) {
            $nama = $user->teacherProfile->nama_lengkap;
            $namaLengkap = $user->teacherProfile->nama_lengkap;
            $fotoProfile = $user->teacherProfile->foto_profile ? url('storage/' . $user->teacherProfile->foto_profile) : null;
        } else if ($role === 'siswa' && $user->studentProfile) {
            $nama = $user->studentProfile->nama_lengkap;
            $namaLengkap = $user->studentProfile->nama_lengkap;
            $fotoProfile = $user->studentProfile->foto_profile ? url('storage/' . $user->studentProfile->foto_profile) : null;
        } else if ($role === 'admin' || $role === 'kepala_sekolah') {
            $nama = $user->username;
            $namaLengkap = $user->username;
        }

        // Notify the teacher if the commenter is not the teacher themselves
        $isContentTeacher = false;
        if ($role === 'guru' && $user->teacherProfile && $user->teacherProfile->id === $content->teacher_id) {
            $isContentTeacher = true;
        }

        if (!$isContentTeacher && $content->teacher_id) {
            \App\Models\Notification::create([
                'teacher_id' => $content->teacher_id,
                'type' => 'forum_comment',
                'message' => "{$nama} menambahkan komentar diskusi pada {$content->tipe} '{$content->judul}'.",
                'data' => json_encode([
                    'content_id' => $content->id,
                    'subject_id' => $content->subject_id,
                    'class_id' => $content->class_id
                ]),
                'is_read' => false
            ]);
        }

        $formattedComment = [
            'id' => $comment->id,
            'komentar' => $comment->komentar,
            'image_path' => $comment->image_path ? url('storage/' . $comment->image_path) : null,
            'is_mine' => true,
            'created_at' => $comment->created_at,
            'user' => [
                'id' => $user->id,
                'nama' => $nama,
                'nama_lengkap' => $namaLengkap,
                'foto_profile' => $fotoProfile,
                'role' => $role
            ]
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Komentar berhasil ditambahkan',
            'data' => $formattedComment
        ], 201);
    }

    public function update(Request $request, $id, $commentId)
    {
        $request->validate([
            'komentar' => 'required|string|max:1000'
        ]);

        $comment = ContentComment::where('content_id', $id)->findOrFail($commentId);

        if ($comment->user_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if (now()->diffInMinutes($comment->created_at) > 2) {
            return response()->json(['status' => 'error', 'message' => 'Waktu edit telah habis (maksimal 2 menit).'], 403);
        }

        $comment->update(['komentar' => $request->komentar]);

        return response()->json(['status' => 'success', 'message' => 'Komentar berhasil diperbarui']);
    }

    public function destroy($id, $commentId)
    {
        $comment = ContentComment::where('content_id', $id)->findOrFail($commentId);

        if ($comment->user_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($comment->image_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($comment->image_path);
        }

        $comment->delete();

        return response()->json(['status' => 'success', 'message' => 'Komentar berhasil dihapus']);
    }
}
