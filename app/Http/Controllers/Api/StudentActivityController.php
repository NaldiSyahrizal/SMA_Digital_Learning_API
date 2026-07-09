<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassroomContent;
use App\Models\StudentSubmission;
use App\Models\StudentSubjectInterest;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Classroom;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class StudentActivityController extends Controller
{
    /**
     * Get student dashboard stats and ranked active assignments/quizzes using the SAW method.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profil siswa tidak ditemukan.'
            ], 404);
        }

        $classroomRelation = $student->studentClassrooms()->first();
        $classId = $classroomRelation ? $classroomRelation->class_id : null;
        $classroom = $classId ? Classroom::find($classId) : null;
        $classroomName = $classroom ? $classroom->nama_kelas : 'Belum Diploting';

        $rankedTasks = [];

        if ($classId) {
            // Fetch all tasks and quizzes for this classroom
            $contents = ClassroomContent::where('class_id', $classId)
                ->whereIn('tipe', ['tugas', 'kuis'])
                ->with(['subject'])
                ->get();

            $activeContents = [];

            foreach ($contents as $content) {
                // Check if completed
                $attemptsCount = StudentSubmission::where('content_id', $content->id)
                    ->where('student_id', $student->id)
                    ->count();

                $isCompleted = false;
                if ($content->tipe === 'kuis') {
                    // Untuk SPK Beranda, jika sudah dikerjakan minimal 1 kali, anggap selesai
                    // agar tidak mengotori daftar prioritas Beranda.
                    if ($attemptsCount >= 1) {
                        $isCompleted = true;
                    }
                } else {
                    // For tugas, if they have submitted or been graded, it's completed
                    $hasSubmission = StudentSubmission::where('content_id', $content->id)
                        ->where('student_id', $student->id)
                        ->whereIn('status', ['submitted', 'graded'])
                        ->exists();
                    if ($hasSubmission) {
                        $isCompleted = true;
                    }
                }

                // If content is closed manually OR closed automatically and past due, and student didn't submit, it's inactive/missed
                $isClosedAndPast = (bool)$content->is_closed || ((bool)$content->close_automatically && $content->due_date && now()->greaterThan($content->due_date));

                if (!$isCompleted && !$isClosedAndPast) {
                    // Calculate remaining seconds for Urgency (Cost)
                    $secs = $content->due_date ? now()->diffInSeconds($content->due_date, false) : 604800; // default 7 days
                    $secs = max(60, $secs); // prevent division by zero and handle overdue open contents safely

                    // Academic Weight (Benefit): weight (1-100) * jam_pelajaran (1-10)
                    $jp = $content->subject->jam_pelajaran ?? 3;
                    $academicWeight = ($content->weight ?? 10) * $jp;

                    // Student Interest (Benefit): 1-5
                    $interest = StudentSubjectInterest::where('student_id', $student->id)
                        ->where('subject_id', $content->subject_id)
                        ->value('interest_score') ?? 0;

                    $activeContents[] = [
                        'content' => $content,
                        'x_c1' => $secs,             // Urgency (Cost)
                        'x_c2' => $academicWeight,   // Academic Weight (Benefit)
                        'x_c3' => $interest,         // Interest (Benefit)
                        'attempts_count' => $attemptsCount
                    ];
                }
            }

            // Perform SAW Normalization and Score Calculation
            if (!empty($activeContents)) {
                // Find mins and maxs
                $minC1 = min(array_column($activeContents, 'x_c1'));
                $maxC2 = max(1, max(array_column($activeContents, 'x_c2')));
                $maxC3 = max(1, max(array_column($activeContents, 'x_c3')));

                // Weights: Urgency = 50%, Weight = 30%, Interest = 20%
                $w1 = 0.50;
                $w2 = 0.30;
                $w3 = 0.20;

                foreach ($activeContents as $item) {
                    $content = $item['content'];

                    // Cost Normalization for C1: min / x
                    $r1 = $minC1 / $item['x_c1'];

                    // Benefit Normalization for C2-C3: x / max
                    $r2 = $item['x_c2'] / $maxC2;
                    $r3 = $item['x_c3'] / $maxC3;

                    // SAW Final Priority Score
                    $sawScore = ($w1 * $r1) + ($w2 * $r2) + ($w3 * $r3);

                    // Map scale descriptions
                    $urgencyDesc = "Mendekati Tenggat";
                    if ($item['x_c1'] > 172800) $urgencyDesc = "Biasa";
                    if ($item['x_c1'] > 432000) $urgencyDesc = "Sangat Santai";

                    $difficultyDesc = "Sedang"; // Fallback statis agar UI Android versi lama tidak pecah
                    $durationDesc = "Cepat"; // Fallback statis agar UI Android versi lama tidak pecah

                    $rankedTasks[] = [
                        'id' => $content->id,
                        'tipe' => $content->tipe,
                        'judul' => $content->judul,
                        'deskripsi' => $content->deskripsi,
                        'subject_name' => $content->subject->nama ?? 'Mapel',
                        'due_date' => $content->due_date ? $content->due_date->toIso8601String() : null,
                        'saw_score' => round($sawScore * 100, 1), // scale 0-100 for display ease
                        'attempts_count' => $item['attempts_count'],
                        'quiz_max_attempts' => $content->quiz_max_attempts,
                        'quiz_duration_minutes' => $content->quiz_duration_minutes,
                        'difficulty_desc' => $difficultyDesc,
                        'duration_desc' => $durationDesc,
                        'allowed_file_types' => $content->allowed_file_types,
                    ];
                }

                // Sort by SAW score descending
                usort($rankedTasks, function ($a, $b) {
                    return $b['saw_score'] <=> $a['saw_score'];
                });
            }
        }

        // Calculate some basic student stats (distinct content items)
        $completedOrGradedCount = StudentSubmission::where('student_id', $student->id)
            ->whereIn('status', ['submitted', 'graded'])
            ->distinct()
            ->count('content_id');
        $gradedTasksCount = StudentSubmission::where('student_id', $student->id)
            ->where('status', 'graded')
            ->distinct()
            ->count('content_id');

        // Calculate student Average Grade
        $averageGrade = StudentSubmission::where('student_id', $student->id)
            ->whereNotNull('nilai')
            ->avg('nilai');

        return response()->json([
            'status' => 'success',
            'data' => [
                'profile' => [
                    'id' => $student->id,
                    'nama_lengkap' => $student->nama_lengkap,
                    'nis' => $student->nis,
                    'kelas' => $classroomName,
                    'foto_profile' => $student->foto_profile ? url('storage/' . $student->foto_profile) : null,
                ],
                'stats' => [
                    'completed_tasks' => $completedOrGradedCount,
                    'graded_tasks' => $gradedTasksCount,
                    'average_grade' => $averageGrade ? round($averageGrade, 1) : 0,
                    'total_pending_tasks' => count($rankedTasks)
                ],
                'priorities' => $rankedTasks
            ]
        ], 200);
    }

    /**
     * Get list of subjects assigned to student package, with student interest score.
     */
    public function subjects(Request $request)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $classroomRelation = $student->studentClassrooms()->first();
        if (!$classroomRelation) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $classroom = Classroom::with('package.subjects')->find($classroomRelation->class_id);
        if (!$classroom || !$classroom->package) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $subjects = $classroom->package->subjects->map(function ($subject) use ($student) {
            $interest = StudentSubjectInterest::where('student_id', $student->id)
                ->where('subject_id', $subject->id)
                ->value('interest_score') ?? 0; // default to 0

            return [
                'id' => $subject->id,
                'kode_mapel' => $subject->kode_mapel,
                'nama' => $subject->nama,
                'kategori' => $subject->kategori,
                'jam_pelajaran' => $subject->jam_pelajaran,
                'interest_score' => $interest
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $subjects
        ], 200);
    }

    /**
     * Save student interest score for a subject.
     */
    public function saveInterest(Request $request, $subjectId)
    {
        $request->validate([
            'interest_score' => 'required|integer|min:1|max:5'
        ]);

        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        StudentSubjectInterest::updateOrCreate(
            ['student_id' => $student->id, 'subject_id' => $subjectId],
            ['interest_score' => $request->interest_score]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Minat pelajaran berhasil disimpan!'
        ], 200);
    }

    /**
     * Get contents (materials, tasks, quizzes) for a subject.
     */
    public function contents(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|exists:subjects,id'
        ]);

        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $classroomRelation = $student->studentClassrooms()->first();
        if (!$classroomRelation) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $contents = ClassroomContent::where('class_id', $classroomRelation->class_id)
            ->where('subject_id', $request->subject_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($content) use ($student) {
                // Get submission details
                $submissions = StudentSubmission::where('content_id', $content->id)
                    ->where('student_id', $student->id)
                    ->orderBy('attempt_number', 'desc')
                    ->get();

                $submissionCount = $submissions->count();
                $latestSubmission = $submissions->first();

                // Highest score for multiple attempts rule
                $bestScore = $submissions->max('nilai');

                $isClosed = (bool)$content->is_closed || ((bool)$content->close_automatically && $content->due_date && now()->greaterThan($content->due_date));
                return [
                    'id' => $content->id,
                    'tipe' => $content->tipe,
                    'judul' => $content->judul,
                    'deskripsi' => $content->deskripsi,
                    'file_path' => $content->file_path ? url('storage/' . $content->file_path) : null,
                    'due_date' => $content->due_date ? $content->due_date->toIso8601String() : null,
                    'is_closed' => $isClosed,
                    'close_automatically' => (bool)$content->close_automatically,
                    'quiz_duration_minutes' => $content->quiz_duration_minutes,
                    'quiz_max_attempts' => $content->quiz_max_attempts,
                    'allowed_file_types' => $content->allowed_file_types,
                    'submission_count' => $submissionCount,
                    'submission_status' => $latestSubmission ? $latestSubmission->status : 'not_submitted',
                    'best_score' => $bestScore,
                    'created_at' => $content->created_at->toIso8601String()
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $contents
        ], 200);
    }

    /**
     * Get detail of classroom content including multiple-choice questions if quiz.
     */
    public function showContent(Request $request, $id)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $content = ClassroomContent::with(['questions'])->findOrFail($id);

        $submissions = StudentSubmission::where('content_id', $content->id)
            ->where('student_id', $student->id)
            ->orderBy('attempt_number', 'desc')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'submission_text' => $s->submission_text,
                    'file_path' => $s->file_path ? url('storage/' . $s->file_path) : null,
                    'nilai' => $s->nilai,
                    'catatan' => $s->catatan,
                    'status' => $s->status,
                    'attempt_number' => $s->attempt_number,
                    'exit_count' => $s->exit_count ? (int)$s->exit_count : 0,
                    'exit_logs' => $s->exit_logs,
                    'updated_at' => $s->updated_at->toIso8601String()
                ];
            });

        $questions = $content->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'tipe_soal' => $q->tipe_soal,
                'pertanyaan' => $q->pertanyaan,
                'opsi_a' => $q->opsi_a,
                'opsi_b' => $q->opsi_b,
                'opsi_c' => $q->opsi_c,
                'opsi_d' => $q->opsi_d,
                'image_path' => $q->image_path ? url('storage/' . $q->image_path) : null,
                // Do NOT expose correct answer to student! Security standard.
            ];
        });

        $data = [
            'id' => $content->id,
            'tipe' => $content->tipe,
            'judul' => $content->judul,
            'deskripsi' => $content->deskripsi,
            'file_path' => $content->file_path ? url('storage/' . $content->file_path) : null,
            'due_date' => $content->due_date ? $content->due_date->toIso8601String() : null,
            'is_closed' => (bool)$content->is_closed || ((bool)$content->close_automatically && $content->due_date && now()->greaterThan($content->due_date)),
            'close_automatically' => (bool)$content->close_automatically,
            'quiz_duration_minutes' => $content->quiz_duration_minutes,
            'quiz_max_attempts' => $content->quiz_max_attempts,
            'allowed_file_types' => $content->allowed_file_types,
            'questions' => $questions,
            'submissions' => $submissions
        ];

        return response()->json([
            'status' => 'success',
            'data' => $data
        ], 200);
    }

    /**
     * Submit assignment or quiz answers.
     */
    public function submitContent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content_id' => 'required|exists:classroom_contents,id',
            'submission_text' => 'nullable|string',
            'file' => 'nullable|file|max:10240', // 10MB limit
            'answers' => 'nullable|string', // JSON mapping: questionId -> studentAnswer
            'exit_count' => 'nullable|integer',
            'exit_logs' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $content = ClassroomContent::findOrFail($request->content_id);

        // 1. Enforce Tutup Otomatis deadline limit or manual close
        if ((bool)$content->is_closed || ((bool)$content->close_automatically && $content->due_date && now()->greaterThan($content->due_date))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Batas waktu pengumpulan telah terlewati atau akses pengerjaan sudah ditutup.'
            ], 422);
        }

        // 2. Prevent resubmission of already graded assignment
        if ($content->tipe !== 'kuis') {
            $hasGraded = StudentSubmission::where('content_id', $content->id)
                ->where('student_id', $student->id)
                ->where('status', 'graded')
                ->exists();
            if ($hasGraded) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tugas ini telah dinilai oleh guru dan tidak dapat dikumpulkan kembali.'
                ], 422);
            }
        }

        // 2. Count existing attempts
        $attemptsCount = StudentSubmission::where('content_id', $content->id)
            ->where('student_id', $student->id)
            ->count();
        $attemptNumber = $attemptsCount + 1;

        if ($content->tipe === 'kuis') {
            $maxAttempts = $content->quiz_max_attempts ?? 1;
            if ($maxAttempts > 0 && $attemptsCount >= $maxAttempts) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Batas jumlah percobaan kuis telah tercapai.'
                ], 422);
            }

            $hasEssay = \App\Models\QuizQuestion::where('content_id', $content->id)
                ->where('tipe_soal', 'essay')
                ->exists();

            // Grade Multiple Choice automatically
            $correctCount = 0;
            $totalQuestions = $content->questions()->count();
            $mcQuestionsCount = $content->questions()->where('tipe_soal', 'pilihan_ganda')->count();

            $studentAnswersArray = [];
            if ($request->filled('answers')) {
                $studentAnswersArray = json_decode($request->answers, true) ?: [];
                if (is_array($studentAnswersArray)) {
                    foreach ($studentAnswersArray as $qId => $answer) {
                        $question = \App\Models\QuizQuestion::where('content_id', $content->id)
                            ->where('id', $qId)
                            ->first();
                        
                        if ($question && $question->tipe_soal === 'pilihan_ganda') {
                            $correctAnswer = $question->jawaban_benar;
                            if ($correctAnswer && strtoupper(trim($answer)) === strtoupper(trim($correctAnswer))) {
                                $correctCount++;
                            }
                        }
                    }
                }
            }

            $nilai = null;
            $status = 'submitted';
            $submissionMessage = "Mengerjakan kuis. Menjawab {$correctCount} benar dari {$mcQuestionsCount} soal pilihan ganda. Menunggu penilaian essay.";

            if (!$hasEssay) {
                $nilai = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100) : 100;
                $status = 'graded';
                $submissionMessage = "Mengerjakan kuis. Menjawab {$correctCount} dari {$totalQuestions} soal dengan benar.";
            }

            $submission = StudentSubmission::create([
                'content_id' => $content->id,
                'student_id' => $student->id,
                'submission_text' => $request->submission_text ?: $submissionMessage,
                'quiz_answers' => json_encode($studentAnswersArray),
                'nilai' => $nilai,
                'status' => $status,
                'attempt_number' => $attemptNumber,
                'exit_count' => $request->input('exit_count', 0),
                'exit_logs' => $request->filled('exit_logs') ? json_decode($request->exit_logs, true) : null
            ]);

            // Notify teacher of quiz submission
            $this->notifyTeacherSubmission($content, $student, $submission);

            $msg = $hasEssay ? 'Kuis selesai dikerjakan! Menunggu penilaian dari guru untuk bagian esai.' : 'Kuis selesai dan berhasil dinilai secara otomatis!';

            return response()->json([
                'status' => 'success',
                'message' => $msg,
                'data' => [
                    'submission_id' => $submission->id,
                    'nilai' => $nilai,
                    'attempt_number' => $attemptNumber,
                    'correct_answers' => $correctCount,
                    'total_questions' => $totalQuestions,
                    'has_essay' => $hasEssay
                ]
            ], 201);
        } else {
            // Task Upload Submission
            // Enforce file format restrictions
            $filePath = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $extension = strtolower($file->getClientOriginalExtension());
                $allowed = $content->allowed_file_types ?? 'all';

                if ($allowed === 'pdf' && $extension !== 'pdf') {
                    return response()->json(['status' => 'error', 'message' => 'Guru menetapkan format unggahan wajib berupa PDF.'], 422);
                }
                if ($allowed === 'image' && !in_array($extension, ['jpg', 'jpeg', 'png'])) {
                    return response()->json(['status' => 'error', 'message' => 'Guru menetapkan format unggahan wajib berupa Gambar (PNG/JPG).'], 422);
                }

                $filePath = $file->store('submissions', 'public');
            }

            $clearFile = $request->boolean('clear_file', false);

            if ($clearFile && $attemptsCount > 0) {
                $oldPath = StudentSubmission::where('content_id', $content->id)->where('student_id', $student->id)->value('file_path');
                if ($oldPath) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Task is single attempt usually. We updateOrCreate to replace/resubmit
            $submission = StudentSubmission::updateOrCreate(
                [
                    'content_id' => $content->id,
                    'student_id' => $student->id,
                ],
                [
                    'submission_text' => $request->submission_text,
                    'file_path' => $clearFile ? null : ($filePath ?: ($attemptsCount > 0 ? StudentSubmission::where('content_id', $content->id)->where('student_id', $student->id)->value('file_path') : null)),
                    'status' => 'submitted',
                    'attempt_number' => 1
                ]
            );

            // Notify teacher of task submission
            $this->notifyTeacherSubmission($content, $student, $submission);

            return response()->json([
                'status' => 'success',
                'message' => 'Tugas berhasil dikumpulkan!',
                'data' => $submission
            ], 201);
        }
    }

    /**
     * Get student profile, class info, and grades statistics.
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $classroomRelation = $student->studentClassrooms()->first();
        $classId = $classroomRelation ? $classroomRelation->class_id : null;
        $classroom = $classId ? Classroom::with('package')->find($classId) : null;

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $student->id,
                'username' => $user->username,
                'email' => $user->email,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
                'jenis_kelamin' => $student->jenis_kelamin,
                'no_telp' => $student->no_telp,
                'foto_profile' => $student->foto_profile ? url('storage/' . $student->foto_profile) : null,
                'kelas' => $classroom ? $classroom->nama_kelas : 'Belum Diploting',
                'paket_jurusan' => $classroom && $classroom->package ? $classroom->package->nama_paket : '-',
            ]
        ], 200);
    }

    /**
     * Update student profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'no_telp' => 'required|string|max:15',
            'password' => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $user->update([
            'username' => $request->username,
            'password' => $request->password ? bcrypt($request->password) : $user->password
        ]);

        $student->update([
            'no_telp' => $request->no_telp
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui!'
        ], 200);
    }

    /**
     * Get dynamic real-time notifications for student.
     */
    public function notifications(Request $request)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json(['status' => 'error', 'message' => 'Profil siswa tidak ditemukan.'], 404);
        }

        $classroomRelation = $student->studentClassrooms()->first();
        if (!$classroomRelation) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $classId = $classroomRelation->class_id;

        // Generate dynamic notifications from content published in student's class and graded submissions
        $notifications = [];

        // 1. Content Published notifications
        $contents = ClassroomContent::where('class_id', $classId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($contents as $item) {
            // Deteksi jika konten diperbarui oleh guru
            $isUpdated = $item->updated_at && $item->updated_at->diffInSeconds($item->created_at) > 5;
            $time = $isUpdated ? $item->updated_at : $item->created_at;
            $action = $isUpdated ? "memperbarui" : "mempublikasikan";

            // Jika diperbarui, gunakan virtual ID dinamis agar notifikasi ter-reset menjadi "unread" di Android
            $virtualId = 100000 + $item->id;
            if ($isUpdated) {
                $virtualId = 3000000 + $item->id + ($item->updated_at->timestamp % 1000) * 100;
            }

            $notifications[] = [
                'id' => $virtualId,
                'type' => 'publication',
                'message' => "Guru {$action} {$item->tipe} baru: '{$item->judul}' untuk mata pelajaran {$item->subject->nama}.",
                'data' => [
                    'content_id' => $item->id,
                    'subject_id' => $item->subject_id,
                    'tipe' => $item->tipe
                ],
                'is_read' => false,
                'created_at' => $time->toIso8601String()
            ];
        }

        // 2. Graded notifications
        $gradedSubmissions = StudentSubmission::where('student_id', $student->id)
            ->where('status', 'graded')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($gradedSubmissions as $item) {
            $content = $item->content;
            if ($content) {
                $notifications[] = [
                    'id' => 200000 + $item->id, // dynamic virtual id
                    'type' => 'grading',
                    'message' => "Pengerjaan '{$content->judul}' Anda telah diperiksa. Nilai: {$item->nilai}. Catatan: " . ($item->catatan ?: '-'),
                    'data' => [
                        'content_id' => $content->id,
                        'subject_id' => $content->subject_id,
                        'nilai' => $item->nilai
                    ],
                    'is_read' => true,
                    'created_at' => $item->updated_at->toIso8601String()
                ];
            }
        }

        // 3. New Discussion Comments notifications
        $recentComments = \App\Models\ContentComment::with(['user', 'content'])
            ->whereHas('content', function ($query) use ($classId) {
                $query->where('class_id', $classId);
            })
            ->where('user_id', '!=', auth()->id())
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
            
        $commentedContents = [];
        foreach ($recentComments as $comment) {
            $cId = $comment->content_id;
            if (!isset($commentedContents[$cId])) {
                $commentedContents[$cId] = $comment;
            }
        }
        
        foreach ($commentedContents as $cId => $comment) {
            $content = $comment->content;
            $nama = $comment->user->username ?? 'Seseorang';
            // Try to get more readable name if possible based on profile, but fallback to username
            $role = $comment->user->roles->first()->name ?? 'unknown';
            if ($role === 'guru' && $comment->user->teacherProfile) {
                $nama = $comment->user->teacherProfile->nama_lengkap;
            } else if ($role === 'siswa' && $comment->user->studentProfile) {
                $nama = $comment->user->studentProfile->nama_lengkap;
            }

            if ($content) {
                $notifications[] = [
                    'id' => 400000 + $comment->id,
                    'type' => 'forum_comment',
                    'message' => "Ada komentar baru di diskusi '{$content->judul}' dari {$nama}.",
                    'data' => [
                        'content_id' => $content->id,
                        'subject_id' => $content->subject_id
                    ],
                    'is_read' => false,
                    'created_at' => $comment->created_at->toIso8601String()
                ];
            }
        }

        // Sort notifications by date descending
        usort($notifications, function ($a, $b) {
            return \Carbon\Carbon::parse($b['created_at']) <=> \Carbon\Carbon::parse($a['created_at']);
        });

        // Deduplicate notifications by type AND content_id (keep only the latest one per type per content)
        $seen = [];
        $uniqueNotifications = [];

        foreach ($notifications as $notif) {
            $contentId = $notif['data']['content_id'] ?? null;
            $type = $notif['type'] ?? 'unknown';
            
            if ($contentId !== null) {
                $key = $type . '_' . $contentId;
                if (in_array($key, $seen)) {
                    continue; // Skip older notification of the SAME type for the same content
                }
                $seen[] = $key;
            }
            $uniqueNotifications[] = $notif;
        }

        return response()->json([
            'status' => 'success',
            'data' => $uniqueNotifications
        ], 200);
    }

    /**
     * Upload and update the authenticated student's profile picture.
     */
    public function uploadPhoto(Request $request)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profil siswa tidak ditemukan.'
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
            if ($student->foto_profile) {
                $oldPath = str_replace(url('storage/'), '', $student->foto_profile);
                $oldPath = ltrim($oldPath, '/');
                Storage::disk('public')->delete($oldPath);
            }

            // Save new photo inside profiles directory under public storage disk
            $path = $request->file('photo')->store('profiles', 'public');
            $student->foto_profile = $path;
            $student->save();

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

    /**
     * Delete student task submission if not graded.
     */
    public function deleteSubmission(Request $request, $id)
    {
        $user = $request->user();
        $student = $user->studentProfile;

        if (!$student) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profil siswa tidak ditemukan.'
            ], 404);
        }

        $submission = StudentSubmission::where('id', $id)
            ->where('student_id', $student->id)
            ->first();

        if (!$submission) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pengumpulan tidak ditemukan.'
            ], 404);
        }

        if ($submission->status === 'graded') {
            return response()->json([
                'status' => 'error',
                'message' => 'Tugas yang sudah dinilai tidak dapat dihapus.'
            ], 422);
        }

        $contentId = $submission->content_id;
        $studentId = $submission->student_id;

        if ($submission->file_path) {
            Storage::disk('public')->delete($submission->file_path);
        }

        $submission->delete();

        // Cari dan hapus notifikasi guru terkait submission ini
        $notifications = \App\Models\Notification::where('type', 'submission')->get();
        foreach ($notifications as $notif) {
            $data = json_decode($notif->data, true);
            if (isset($data['content_id']) && $data['content_id'] == $contentId) {
                $notifStudentId = $data['student_id'] ?? null;
                if (!$notifStudentId && isset($data['submission_id'])) {
                    $notifStudentId = ($data['submission_id'] == $id) ? $studentId : null;
                }
                if ($notifStudentId == $studentId) {
                    $notif->delete();
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pengumpulan tugas berhasil dihapus!'
        ], 200);
    }

    /**
     * Notify teacher about student submission (both Database and Firebase FCM).
     */
    private function notifyTeacherSubmission($content, $student, $submission)
    {
        try {
            $classroom = \App\Models\Classroom::find($content->class_id);
            $subject = \App\Models\Subject::find($content->subject_id);

            $classroomName = $classroom ? $classroom->nama_kelas : 'Kelas';
            $subjectName = $subject ? $subject->nama : 'Mapel';

            $tipe = $content->tipe === 'kuis' ? 'kuis' : 'tugas';
            $actionWord = $content->tipe === 'kuis' ? 'menyelesaikan' : 'mengumpulkan';
            $emoji = $content->tipe === 'kuis' ? '🏆' : '📝';

            $message = "Siswa {$student->nama_lengkap} (NIS: {$student->nis}) telah {$actionWord} {$tipe}: {$content->judul}.";

            // Hapus notifikasi lama untuk siswa & konten yang sama agar database tetap bersih
            $oldNotifications = \App\Models\Notification::where('teacher_id', $content->teacher_id)
                ->where('type', 'submission')
                ->get();

            foreach ($oldNotifications as $oldNotif) {
                $oldData = json_decode($oldNotif->data, true);
                if (isset($oldData['content_id']) && $oldData['content_id'] == $content->id) {
                    $oldStudentId = $oldData['student_id'] ?? null;
                    if (!$oldStudentId && isset($oldData['submission_id'])) {
                        $oldSub = \App\Models\StudentSubmission::find($oldData['submission_id']);
                        $oldStudentId = $oldSub ? $oldSub->student_id : null;
                    }

                    if ($oldStudentId == $student->id) {
                        $oldNotif->delete();
                    }
                }
            }

            // 1. Create DB Notification
            \App\Models\Notification::create([
                'teacher_id' => $content->teacher_id,
                'type' => 'submission',
                'message' => $message,
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

            // 2. Send FCM Push Notification if token exists
            $teacherUser = \App\Models\User::whereHas('teacherProfile', function ($q) use ($content) {
                $q->where('id', $content->teacher_id);
            })->first();

            if ($teacherUser && $teacherUser->device_token) {
                $fcmPayload = [
                    'title' => "{$emoji} Pengumpulan {$content->tipe}: {$content->judul}",
                    'body' => "Siswa {$student->nama_lengkap} telah {$actionWord} {$content->tipe}.",
                    'type' => 'submission',
                    'class_id' => (string)$content->class_id,
                    'class_name' => $classroomName,
                    'subject_id' => (string)$content->subject_id,
                    'subject_name' => $subjectName,
                    'content_id' => (string)$content->id,
                    'submission_id' => (string)$submission->id,
                ];

                $this->sendFcmPush($teacherUser->device_token, $fcmPayload);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('notifyTeacherSubmission error: ' . $e->getMessage());
        }
    }

    /**
     * Send Push Notification to Firebase FCM
     */
    private function sendFcmPush($token, $payload)
    {
        try {
            $url = 'https://fcm.googleapis.com/fcm/send';
            
            \Illuminate\Support\Facades\Log::info('FCM Transmission Packet Outgoing:', [
                'target_device_token' => $token,
                'payload_data' => $payload
            ]);

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
}
