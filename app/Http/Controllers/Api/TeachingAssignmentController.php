<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeachingAssignment;
use Illuminate\Http\Request;

class TeachingAssignmentController extends Controller
{
    public function index()
    {
        $assignments = TeachingAssignment::with(['teacher.user', 'classroom', 'subject'])->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'teacher_id' => $item->teacher_id,
                'class_id' => $item->class_id,
                'subject_id' => $item->subject_id,
                'teacher_name' => $item->teacher->nama_lengkap,
                'class_name' => $item->classroom->nama_kelas,
                'subject_name' => $item->subject->nama,
            ];
        });
        return response()->json($assignments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|exists:teacher_profiles,id',
            'class_id' => 'required|exists:classrooms,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        // Check duplicate mapping for ANY teacher
        $existsAnother = TeachingAssignment::where('class_id', $validated['class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->first();

        if ($existsAnother) {
            if ($existsAnother->teacher_id == $validated['teacher_id']) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Guru sudah diploting ke kelas ini.',
                    'data' => $existsAnother
                ], 200);
            }
            
            $existsAnother->load('teacher');
            $teacherName = $existsAnother->teacher ? $existsAnother->teacher->nama_lengkap : 'guru lain';
            return response()->json([
                'status' => 'error',
                'message' => "Mata pelajaran ini sudah diampu oleh {$teacherName} di kelas tersebut."
            ], 422);
        }

        $assignment = TeachingAssignment::create($validated);

        // Retrieve classroom and subject names to populate dynamic message
        $classroom = \App\Models\Classroom::find($assignment->class_id);
        $subject = \App\Models\Subject::find($assignment->subject_id);
        
        $classroomName = $classroom ? $classroom->nama_kelas : 'Kelas';
        $subjectName = $subject ? $subject->nama : 'Mapel';
        
        $message = "Anda telah diplot untuk mengajar mata pelajaran {$subjectName} di kelas {$classroomName} oleh Admin.";
        $this->notifyTeacher($assignment->teacher_id, $message, $assignment->class_id, $classroomName, $assignment->subject_id, $subjectName);

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil memploting guru ke mata pelajaran dan kelas',
            'data' => $assignment
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|exists:teacher_profiles,id',
            'class_id' => 'required|exists:classrooms,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        $assignment = TeachingAssignment::findOrFail($id);
        $oldTeacherId = $assignment->teacher_id;
        $oldClassId = $assignment->class_id;
        $oldSubjectId = $assignment->subject_id;

        // Check duplicate mapping for ANY teacher (excluding current record)
        $existsAnother = TeachingAssignment::where('class_id', $validated['class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->where('id', '!=', $id)
            ->first();

        if ($existsAnother) {
            $existsAnother->load('teacher');
            $teacherName = $existsAnother->teacher ? $existsAnother->teacher->nama_lengkap : 'guru lain';
            return response()->json([
                'status' => 'error',
                'message' => "Mata pelajaran ini sudah diampu oleh {$teacherName} di kelas tersebut."
            ], 422);
        }

        $assignment->update($validated);

        // Retrieve classroom and subject names
        $classroom = \App\Models\Classroom::find($assignment->class_id);
        $subject = \App\Models\Subject::find($assignment->subject_id);
        
        $classroomName = $classroom ? $classroom->nama_kelas : 'Kelas';
        $subjectName = $subject ? $subject->nama : 'Mapel';

        if ($oldTeacherId != $assignment->teacher_id) {
            // Teacher reassigned: Notify old teacher about removal, and new teacher about assignment
            $oldClassroom = \App\Models\Classroom::find($oldClassId);
            $oldSubject = \App\Models\Subject::find($oldSubjectId);
            $oldClassroomName = $oldClassroom ? $oldClassroom->nama_kelas : 'Kelas';
            $oldSubjectName = $oldSubject ? $oldSubject->nama : 'Mapel';

            $oldMessage = "Ploting Dialihkan: Pengampu mata pelajaran {$oldSubjectName} di kelas {$oldClassroomName} telah dialihkan ke guru lain oleh Admin.";
            $this->notifyTeacher($oldTeacherId, $oldMessage);

            $newMessage = "Anda telah diplot untuk mengajar mata pelajaran {$subjectName} di kelas {$classroomName} oleh Admin.";
            $this->notifyTeacher($assignment->teacher_id, $newMessage, $assignment->class_id, $classroomName, $assignment->subject_id, $subjectName);
        } else {
            // Classroom/Subject changed for the same teacher
            $message = "Perubahan Ploting: Detail mengajar Anda diperbarui oleh Admin menjadi mata pelajaran {$subjectName} di kelas {$classroomName}.";
            $this->notifyTeacher($assignment->teacher_id, $message, $assignment->class_id, $classroomName, $assignment->subject_id, $subjectName);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil memperbarui ploting guru',
            'data' => $assignment
        ]);
    }

    public function destroy($id)
    {
        $assignment = TeachingAssignment::findOrFail($id);
        $teacherId = $assignment->teacher_id;
        
        $classroom = \App\Models\Classroom::find($assignment->class_id);
        $subject = \App\Models\Subject::find($assignment->subject_id);
        
        $classroomName = $classroom ? $classroom->nama_kelas : 'Kelas';
        $subjectName = $subject ? $subject->nama : 'Mapel';

        $assignment->delete();

        $message = "Ploting Dicabut: Anda tidak lagi mengampu mata pelajaran {$subjectName} di kelas {$classroomName}.";
        $this->notifyTeacher($teacherId, $message);

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus ploting guru'
        ]);
    }

    /**
     * Reusable private helper to trigger database notifications and FCM push payloads
     */
    private function notifyTeacher($teacherId, $message, $classId = null, $className = null, $subjectId = null, $subjectName = null)
    {
        // 1. Write to local database notifications table
        \App\Models\Notification::create([
            'teacher_id' => $teacherId,
            'type' => 'plotting',
            'message' => $message,
            'data' => $classId ? json_encode([
                'class_id' => (int)$classId,
                'class_name' => $className,
                'subject_id' => (int)$subjectId,
                'subject_name' => $subjectName,
            ]) : null,
            'is_read' => false
        ]);

        // 2. Dispatch FCM push request if a device token exists
        $teacherUser = \App\Models\User::whereHas('teacherProfile', function ($q) use ($teacherId) {
            $q->where('id', $teacherId);
        })->first();

        if ($teacherUser && $teacherUser->device_token) {
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
                
                $payload = [
                    'to' => $teacherUser->device_token,
                    'notification' => [
                        'title' => '📌 Informasi Ploting Kelas',
                        'body' => $message,
                        'sound' => 'default',
                        'badge' => '1',
                    ]
                ];

                if ($classId) {
                    $payload['data'] = [
                        'type' => 'plotting',
                        'class_id' => (string)$classId,
                        'class_name' => $className,
                        'subject_id' => (string)$subjectId,
                        'subject_name' => $subjectName,
                    ];
                }

                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) {
                // Fail silently
            }
        }
    }
}
