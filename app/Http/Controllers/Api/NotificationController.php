<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\ClassroomContent;
use App\Models\StudentProfile;
use App\Models\StudentSubmission;
use App\Models\Classroom;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Get list of notifications for the authenticated teacher.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya Guru yang dapat mengakses notifikasi.'
            ], 403);
        }
        $notifications = Notification::where('teacher_id', $teacher->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) {
                $data = $item->data ? json_decode($item->data, true) : null;
                $isGraded = false;
                $studentId = $data['student_id'] ?? null;
                if ($item->type === 'submission' && isset($data['submission_id'])) {
                    $submission = StudentSubmission::find($data['submission_id']);
                    if ($submission) {
                        if (!$studentId) {
                            $studentId = $submission->student_id;
                        }
                        if ($submission->nilai !== null) {
                            $isGraded = true;
                        }
                    }
                }
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'message' => $item->message,
                    'data' => $data,
                    'student_id' => $studentId,
                    'is_read' => (bool) $item->is_read,
                    'is_graded' => $isGraded,
                    'created_at' => $item->created_at->toIso8601String(),
                ];
            });

        // Deduplicate notifications (keep only the latest one per student+content for submissions, or per content for others)
        $seen = [];
        $uniqueNotifications = [];

        foreach ($notifications as $item) {
            $key = null;
            if ($item['type'] === 'submission') {
                $contentId = $item['data']['content_id'] ?? null;
                $studentId = $item['student_id'];
                if ($studentId && $contentId) {
                    $key = 'submission_' . $studentId . '_' . $contentId;
                } elseif (isset($item['data']['submission_id'])) {
                    $key = 'submission_legacy_' . $item['data']['submission_id'];
                }
            } elseif (isset($item['data']['content_id'])) {
                $key = 'content_' . $item['data']['content_id'];
            }

            if ($key !== null) {
                if (in_array($key, $seen)) {
                    continue;
                }
                $seen[] = $key;
            }
            $uniqueNotifications[] = $item;
        }

        return response()->json([
            'status' => 'success',
            'data' => $uniqueNotifications
        ], 200);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak.'
            ], 403);
        }

        $notification = Notification::where('teacher_id', $teacher->id)->findOrFail($id);
        $notification->update(['is_read' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Notifikasi berhasil ditandai dibaca'
        ], 200);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak.'
            ], 403);
        }

        Notification::where('teacher_id', $teacher->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Seluruh notifikasi berhasil ditandai dibaca'
        ], 200);
    }

    /**
     * Update device token for Firebase push notifications.
     */
    public function updateDeviceToken(Request $request)
    {
        $request->validate([
            'device_token' => 'required|string'
        ]);

        $user = $request->user();
        $user->update(['device_token' => $request->device_token]);

        return response()->json([
            'status' => 'success',
            'message' => 'Device token berhasil disimpan'
        ], 200);
    }

    /**
     * Simulate student submission of content to trigger a teacher notification.
     * Perfect for testing and demonstration!
     */
    public function simulateSubmission(Request $request)
    {
        // Dynamic fallback logic to guarantee zero-configuration testing!
        $contentId = $request->content_id;
        $studentId = $request->student_id;
        
        $user = $request->user();
        $teacher = $user ? $user->teacherProfile : null;

        if (empty($contentId) || $contentId == 0) {
            // Find any content for the current authenticated teacher (must be task or quiz to trigger grading)
            $firstContent = ClassroomContent::where('tipe', '!=', 'materi')
                ->when($teacher, function ($q) use ($teacher) {
                    return $q->where('teacher_id', $teacher->id);
                })
                ->first();
            
            if (!$firstContent) {
                $firstContent = ClassroomContent::where('tipe', '!=', 'materi')->first();
            }
            if (!$firstContent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Belum ada konten tugas atau kuis di database untuk disimulasikan.'
                ], 422);
            }
            $contentId = $firstContent->id;
        }

        if (empty($studentId) || $studentId == 0) {
            $content = ClassroomContent::find($contentId);
            // Find any student assigned to the same class as the content
            $classStudent = \App\Models\StudentClassroom::where('class_id', $content->class_id)->first();
            if ($classStudent) {
                $studentId = $classStudent->student_id;
            } else {
                $firstStudent = StudentProfile::first();
                if (!$firstStudent) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Belum ada siswa di database.'
                    ], 422);
                }
                $studentId = $firstStudent->id;
            }
        }

        $request->merge([
            'content_id' => $contentId,
            'student_id' => $studentId
        ]);

        $validator = Validator::make($request->all(), [
            'content_id' => 'required|exists:classroom_contents,id',
            'student_id' => 'required|exists:student_profiles,id',
            'submission_text' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        $content = ClassroomContent::findOrFail($request->content_id);
        $student = StudentProfile::findOrFail($request->student_id);

        // Enforce is_closed logic
        if ($content->is_closed && $content->due_date && now()->greaterThan($content->due_date)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Batas waktu pengumpulan telah terlewati. Konten ini sudah ditutup otomatis.'
            ], 422);
        }

        // Find or create student submission
        $submission = StudentSubmission::updateOrCreate(
            [
                'content_id' => $content->id,
                'student_id' => $student->id,
            ],
            [
                'submission_text' => $request->submission_text ?: 'Mengumpulkan lembar tugas hasil pengerjaan mandiri.',
                'status' => 'submitted',
            ]
        );

        $classroom = Classroom::find($content->class_id);
        $subject = Subject::find($content->subject_id);

        $classroomName = $classroom ? $classroom->nama_kelas : 'Kelas';
        $subjectName = $subject ? $subject->nama : 'Mapel';

        // Hapus notifikasi lama untuk siswa & konten yang sama
        $oldNotifications = Notification::where('teacher_id', $content->teacher_id)
            ->where('type', 'submission')
            ->get();

        foreach ($oldNotifications as $oldNotif) {
            $oldData = json_decode($oldNotif->data, true);
            if (isset($oldData['content_id']) && $oldData['content_id'] == $content->id) {
                $oldStudentId = $oldData['student_id'] ?? null;
                if (!$oldStudentId && isset($oldData['submission_id'])) {
                    $oldSub = StudentSubmission::find($oldData['submission_id']);
                    $oldStudentId = $oldSub ? $oldSub->student_id : null;
                }
                if ($oldStudentId == $student->id) {
                    $oldNotif->delete();
                }
            }
        }

        // Trigger Notification for the teacher
        Notification::create([
            'teacher_id' => $content->teacher_id,
            'type' => 'submission',
            'message' => "Siswa {$student->nama_lengkap} (NIS: {$student->nis}) baru saja mengumpulkan: {$content->judul}.",
            'data' => json_encode([
                'class_id' => (int)$content->class_id,
                'class_name' => $classroomName,
                'subject_id' => (int)$content->subject_id,
                'subject_name' => $subjectName,
                'content_id' => (int)$content->id,
                'submission_id' => (int)$submission->id,
                'student_id' => (int)$student->id
            ]),
            'is_read' => false
        ]);

        // Send push notification using Firebase FCM if token is present!
        $teacherUser = \App\Models\User::whereHas('teacherProfile', function ($q) use ($content) {
            $q->where('id', $content->teacher_id);
        })->first();

        if ($teacherUser && $teacherUser->device_token) {
            $this->sendFcmPush($teacherUser->device_token, [
                'title' => '📝 Pengumpulan Baru: ' . $content->judul,
                'body' => "Siswa {$student->nama_lengkap} baru saja mengumpulkan tugas.",
                'type' => 'submission',
                'class_id' => (string)$content->class_id,
                'class_name' => $classroomName,
                'subject_id' => (string)$content->subject_id,
                'subject_name' => $subjectName,
                'content_id' => (string)$content->id,
                'submission_id' => (string)$submission->id,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Simulasi pengumpulan siswa berhasil dikirim dan memicu notifikasi guru!',
            'data' => $submission
        ], 200);
    }

    /**
     * Send Push Notification to Firebase FCM
     */
    private function sendFcmPush($token, $payload)
    {
        try {
            $url = 'https://fcm.googleapis.com/fcm/send';
            
            // Note: In modern FCM v1 HTTP API, it requires OAuth2 authentication, 
            // but for simple testing and backward compatibility, the Legacy FCM HTTP API uses Server Key.
            // Since we want to make it highly robust, we can log the transmission payload to local logs 
            // so we can see the outgoing Firebase request packet beautifully!
            \Illuminate\Support\Facades\Log::info('FCM Transmission Packet Outgoing:', [
                'target_device_token' => $token,
                'payload_data' => $payload
            ]);

            // Setup cURL request to Firebase if credentials are standard
            $serverKey = env('FCM_SERVER_KEY', 'MOCK_SERVER_KEY');
            
            $headers = [
                'Authorization: key=' . $serverKey,
                'Content-Type: application/json',
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'to' => $token,
                'notification' => [
                    'title' => $payload['title'],
                    'body' => $payload['body'],
                    'sound' => 'default',
                    'badge' => '1',
                ],
                'data' => $payload
            ]));
            
            $result = curl_exec($ch);
            curl_close($ch);
            return $result;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('FCM Transmission Failure: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a single notification.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak.'
            ], 403);
        }

        $notification = Notification::where('teacher_id', $teacher->id)->findOrFail($id);
        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notifikasi berhasil dihapus'
        ], 200);
    }

    /**
     * Delete multiple notifications.
     */
    public function deleteMultiple(Request $request)
    {
        $user = $request->user();
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak.'
            ], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:notifications,id'
        ]);

        Notification::where('teacher_id', $teacher->id)
            ->whereIn('id', $request->ids)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Seluruh notifikasi terpilih berhasil dihapus'
        ], 200);
    }
}
