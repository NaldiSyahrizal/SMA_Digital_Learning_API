<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassroomContent;
use App\Models\QuizQuestion;
use App\Models\StudentSubmission;
use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ClassroomContentController extends Controller
{
    /**
     * Get contents (materials, tasks, quizzes) for a class and subject.
     */
    public function index(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classrooms,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        $classId = $request->class_id;
        $subjectId = $request->subject_id;

        // Fetch all contents
        $contents = ClassroomContent::where('class_id', $classId)
            ->where('subject_id', $subjectId)
            ->with(['questions'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($content) use ($classId) {
                // Get count of total students in this classroom
                $classroom = Classroom::find($classId);
                $totalStudents = $classroom ? $classroom->studentClassrooms()->count() : 0;

                // Submissions progress
                $totalSubmissions = $content->submissions()
                    ->whereIn('status', ['submitted', 'graded'])
                    ->distinct()
                    ->count('student_id');
                $gradedCount = $content->submissions()
                    ->where('status', 'graded')
                    ->distinct()
                    ->count('student_id');

                // Format files to full public URL
                $filePath = $content->file_path ? url('storage/' . $content->file_path) : null;

                // Process question images to full URL
                $questions = $content->questions->map(function ($q) {
                    return [
                        'id' => $q->id,
                        'pertanyaan' => $q->pertanyaan,
                        'opsi_a' => $q->opsi_a,
                        'opsi_b' => $q->opsi_b,
                        'opsi_c' => $q->opsi_c,
                        'opsi_d' => $q->opsi_d,
                        'jawaban_benar' => $q->jawaban_benar,
                        'image_path' => $q->image_path ? url('storage/' . $q->image_path) : null,
                    ];
                });

                return [
                    'id' => $content->id,
                    'tipe' => $content->tipe,
                    'judul' => $content->judul,
                    'deskripsi' => $content->deskripsi,
                    'file_path' => $filePath,
                    'due_date' => $content->due_date ? $content->due_date->toIso8601String() : null,
                    'is_closed' => (bool) $content->is_closed,
                    'close_automatically' => (bool) $content->close_automatically,
                    'difficulty' => (int) $content->difficulty,
                    'weight' => (int) $content->weight,
                    'estimated_duration' => (int) $content->estimated_duration,
                    'quiz_duration_minutes' => $content->quiz_duration_minutes ? (int) $content->quiz_duration_minutes : null,
                    'quiz_max_attempts' => $content->quiz_max_attempts !== null ? (int) $content->quiz_max_attempts : null,
                    'allowed_file_types' => $content->allowed_file_types,
                    'total_students' => $totalStudents,
                    'total_submissions' => $totalSubmissions,
                    'total_graded' => $gradedCount,
                    'questions' => $questions,
                    'created_at' => $content->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $contents
        ], 200);
    }

    /**
     * Store new classroom content (Material, Assignment, or Quiz).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classrooms,id',
            'class_ids' => 'nullable', // Can be array or JSON string
            'subject_id' => 'required|exists:subjects,id',
            'tipe' => 'required|in:materi,tugas,kuis',
            'judul' => 'required|string|max:255',
            'deskripsi' => 'required|string',
            'file' => 'nullable|file|max:10240', // 10MB max
            'due_date' => 'nullable|date',
            'is_closed' => 'nullable|boolean',
            'close_automatically' => 'nullable|boolean',
            'questions' => 'nullable|string', // JSON string for multiple choice questions
            'difficulty' => 'nullable|integer|min:1|max:5',
            'weight' => 'nullable|integer|min:1|max:100',
            'estimated_duration' => 'nullable|integer|min:1|max:5',
            'quiz_duration_minutes' => 'nullable|integer|min:1',
            'quiz_max_attempts' => 'nullable|integer|min:0',
            'allowed_file_types' => 'nullable|in:pdf,image,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user = $request->user();
        $teacher = $user->teacherProfile;

        if (!$teacher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya Guru yang dapat menambahkan konten.'
            ], 403);
        }

        // Handle attachment file upload
        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('contents', 'public');
        }

        // Generate group_id to cluster duplicate pararel contents
        $groupId = (string) \Illuminate\Support\Str::uuid();

        // Get class_ids (decode if sent as JSON string or fallback to class_id array)
        $classIds = $request->class_ids;
        if (is_string($classIds)) {
            $classIds = json_decode($classIds, true);
        }
        if (!is_array($classIds) || empty($classIds)) {
            $classIds = [$request->class_id];
        }

        $primaryContent = null;
        $cachedQuestionImages = []; // Cache uploaded question images path to avoid duplicate saving on disk

        foreach ($classIds as $cId) {
            // Save content for each class in the pararel group
            $content = ClassroomContent::create([
                'class_id' => $cId,
                'subject_id' => $request->subject_id,
                'teacher_id' => $teacher->id,
                'group_id' => $groupId,
                'tipe' => $request->tipe,
                'judul' => $request->judul,
                'deskripsi' => $request->deskripsi,
                'file_path' => $filePath,
                'due_date' => $request->due_date ? new \DateTime($request->due_date) : null,
                'is_closed' => $request->boolean('close_automatically') ? false : $request->boolean('is_closed'),
                'close_automatically' => $request->boolean('close_automatically'),
                'difficulty' => $request->difficulty ?? 3,
                'weight' => $request->weight ?? 10,
                'estimated_duration' => $request->estimated_duration ?? 2,
                'quiz_duration_minutes' => $request->quiz_duration_minutes,
                'quiz_max_attempts' => $request->quiz_max_attempts ?? 1,
                'allowed_file_types' => $request->allowed_file_types ?? 'all',
            ]);

            if ($cId == $request->class_id) {
                $primaryContent = $content;
            } else if (!$primaryContent) {
                $primaryContent = $content;
            }

            // Process questions if quiz
            if ($request->tipe === 'kuis' && $request->filled('questions')) {
                $questionsData = json_decode($request->questions, true);

                if (is_array($questionsData)) {
                    foreach ($questionsData as $index => $qData) {
                        $qImagePath = null;
                        $fileKey = "question_image_" . $index;

                        if (isset($cachedQuestionImages[$index])) {
                            $qImagePath = $cachedQuestionImages[$index];
                        } else if ($request->hasFile($fileKey)) {
                            $qImagePath = $request->file($fileKey)->store('quizzes', 'public');
                            $cachedQuestionImages[$index] = $qImagePath;
                        } else if (isset($qData['image_path']) && !empty($qData['image_path'])) {
                            // Preserve existing image if it is a relative path
                            $imagePathStr = $qData['image_path'];
                            $pos = strpos($imagePathStr, 'quizzes/');
                            if ($pos !== false) {
                                $qImagePath = substr($imagePathStr, $pos);
                                $cachedQuestionImages[$index] = $qImagePath;
                            }
                        }

                        QuizQuestion::create([
                            'content_id' => $content->id,
                            'tipe_soal' => $qData['tipe_soal'] ?? 'pilihan_ganda',
                            'pertanyaan' => $qData['pertanyaan'],
                            'opsi_a' => $qData['opsi_a'],
                            'opsi_b' => $qData['opsi_b'],
                            'opsi_c' => $qData['opsi_c'],
                            'opsi_d' => $qData['opsi_d'],
                            'jawaban_benar' => $qData['jawaban_benar'],
                            'image_path' => $qImagePath,
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Konten berhasil ditambahkan ke kelas pararel',
            'data' => $primaryContent
        ], 201);
    }

    /**
     * Delete classroom content.
     */
    public function destroy($id)
    {
        $content = ClassroomContent::findOrFail($id);
        $groupId = $content->group_id;

        if ($groupId) {
            $contents = ClassroomContent::where('group_id', $groupId)->get();
        } else {
            $contents = collect([$content]);
        }

        // Collect all file paths to delete
        $filesToDelete = [];
        foreach ($contents as $c) {
            if ($c->file_path) {
                $filesToDelete[] = $c->file_path;
            }
            foreach ($c->questions as $q) {
                if ($q->image_path) {
                    $filesToDelete[] = $q->image_path;
                }
            }
        }

        // Delete unique files from storage disk
        foreach (array_unique($filesToDelete) as $file) {
            Storage::disk('public')->delete($file);
        }

        // Delete all DB contents
        foreach ($contents as $c) {
            $c->questions()->delete();

            // Hapus semua notifikasi guru yang berkaitan dengan konten yang dihapus ini
            \App\Models\Notification::where('type', 'submission')
                ->where(function ($query) use ($c) {
                    $query->where('data', 'like', '%"content_id":' . $c->id . '%')
                          ->orWhere('data', 'like', '%"content_id": ' . $c->id . '%');
                })
                ->delete();

            $c->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => $groupId ? 'Seluruh konten pararel berhasil dihapus' : 'Konten berhasil dihapus'
        ], 200);
    }

    /**
     * Get list of student submissions for a specific content.
     */
    public function getSubmissions($contentId)
    {
        $content = ClassroomContent::findOrFail($contentId);
        $classroom = Classroom::findOrFail($content->class_id);

        // Fetch all students enrolled in this classroom
        $studentClassrooms = $classroom->studentClassrooms()->with('student')->get();

        $data = $studentClassrooms->map(function ($sc) use ($contentId, $content) {
            $student = $sc->student;
            
            if (!$student) {
                return null;
            }

            // Get all submissions to calculate best score and latest attempt details
            $submissions = StudentSubmission::where('content_id', $contentId)
                ->where('student_id', $student->id)
                ->get();

            $latestSubmission = $submissions->sortByDesc('attempt_number')->first();
            
            // Quiz uses best score rule, Assignment uses the actual score from the latest graded submission
            $score = null;
            if ($latestSubmission) {
                $score = ($content->tipe === 'kuis') ? $submissions->max('nilai') : $latestSubmission->nilai;
            }

            return [
                'student_id' => $student->id,
                'student_name' => $student->nama_lengkap,
                'nis' => $student->nis,
                'submission_id' => $latestSubmission ? $latestSubmission->id : null,
                'submission_text' => $latestSubmission ? $latestSubmission->submission_text : null,
                'file_path' => ($latestSubmission && $latestSubmission->file_path) ? url('storage/' . $latestSubmission->file_path) : null,
                'nilai' => $score,
                'catatan' => $latestSubmission ? $latestSubmission->catatan : null,
                'status' => $latestSubmission ? $latestSubmission->status : 'not_submitted',
                'quiz_answers' => $latestSubmission ? json_decode($latestSubmission->quiz_answers, true) : null,
                'exit_count' => $latestSubmission ? (int)$latestSubmission->exit_count : 0,
                'exit_logs' => $latestSubmission ? $latestSubmission->exit_logs : null,
                'updated_at' => $latestSubmission ? $latestSubmission->updated_at->toIso8601String() : null,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => $data
        ], 200);
    }

    /**
     * Grade student submission.
     */
    public function gradeSubmission(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nilai' => 'required|integer|min:0|max:100',
            'catatan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        $submission = StudentSubmission::findOrFail($id);
        $submission->update([
            'nilai' => $request->nilai,
            'catatan' => $request->catatan,
            'status' => 'graded'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Nilai berhasil disimpan',
            'data' => $submission
        ], 200);
    }

    /**
     * Get detail of a specific content.
     */
    public function show($id)
    {
        $content = ClassroomContent::with(['questions'])->findOrFail($id);
        
        $classId = $content->class_id;
        $classroom = Classroom::find($classId);
        $totalStudents = $classroom ? $classroom->studentClassrooms()->count() : 0;

        $totalSubmissions = $content->submissions()
            ->whereIn('status', ['submitted', 'graded'])
            ->distinct()
            ->count('student_id');
        $gradedCount = $content->submissions()
            ->where('status', 'graded')
            ->distinct()
            ->count('student_id');

        $filePath = $content->file_path ? url('storage/' . $content->file_path) : null;

        $questions = $content->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'tipe_soal' => $q->tipe_soal,
                'pertanyaan' => $q->pertanyaan,
                'opsi_a' => $q->opsi_a,
                'opsi_b' => $q->opsi_b,
                'opsi_c' => $q->opsi_c,
                'opsi_d' => $q->opsi_d,
                'jawaban_benar' => $q->jawaban_benar,
                'image_path' => $q->image_path ? url('storage/' . $q->image_path) : null,
            ];
        });

        $data = [
            'id' => $content->id,
            'tipe' => $content->tipe,
            'judul' => $content->judul,
            'deskripsi' => $content->deskripsi,
            'file_path' => $filePath,
            'due_date' => $content->due_date ? $content->due_date->toIso8601String() : null,
            'is_closed' => (bool) $content->is_closed,
            'close_automatically' => (bool) $content->close_automatically,
            'difficulty' => (int) $content->difficulty,
            'weight' => (int) $content->weight,
            'estimated_duration' => (int) $content->estimated_duration,
            'quiz_duration_minutes' => $content->quiz_duration_minutes ? (int) $content->quiz_duration_minutes : null,
            'quiz_max_attempts' => $content->quiz_max_attempts !== null ? (int) $content->quiz_max_attempts : null,
            'allowed_file_types' => $content->allowed_file_types,
            'total_students' => $totalStudents,
            'total_submissions' => $totalSubmissions,
            'total_graded' => $gradedCount,
            'questions' => $questions,
            'active_group_class_ids' => $content->group_id 
                ? ClassroomContent::where('group_id', $content->group_id)->pluck('class_id')->map(function($val) { return (int)$val; }) 
                : [(int)$content->class_id],
            'created_at' => $content->created_at->toIso8601String(),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $data
        ], 200);
    }

    /**
     * Update existing classroom content.
     */
    public function update(Request $request, $id)
    {
        $content = ClassroomContent::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'judul' => 'required|string|max:255',
            'deskripsi' => 'required|string',
            'file' => 'nullable|file|max:10240', // 10MB max
            'due_date' => 'nullable|date',
            'questions' => 'nullable|string', // JSON questions string
            'is_closed' => 'nullable|boolean',
            'close_automatically' => 'nullable|boolean',
            'class_ids' => 'nullable', // Can be array or JSON string
            'difficulty' => 'nullable|integer|min:1|max:5',
            'weight' => 'nullable|integer|min:1|max:100',
            'estimated_duration' => 'nullable|integer|min:1|max:5',
            'quiz_duration_minutes' => 'nullable|integer|min:1',
            'quiz_max_attempts' => 'nullable|integer|min:0',
            'allowed_file_types' => 'nullable|in:pdf,image,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        // Get class_ids from request
        $classIds = $request->class_ids;
        if (is_string($classIds)) {
            $classIds = json_decode($classIds, true);
        }
        if (!is_array($classIds) || empty($classIds)) {
            $classIds = [$content->class_id];
        }

        $groupId = $content->group_id;

        // If multiple classes are selected but there's no group_id, generate one!
        if (empty($groupId) && count($classIds) > 1) {
            $groupId = (string) \Illuminate\Support\Str::uuid();
            $content->update(['group_id' => $groupId]);
        }

        // Handle attachment file upload replacement
        $filePath = $content->file_path;
        if ($request->hasFile('file')) {
            // Delete old file if exists
            if ($content->file_path) {
                Storage::disk('public')->delete($content->file_path);
            }
            $filePath = $request->file('file')->store('contents', 'public');
        }

        $dueDate = $request->due_date ? new \DateTime($request->due_date) : null;
        $closeAutomatically = $request->has('close_automatically') ? $request->boolean('close_automatically') : $content->close_automatically;
        $isClosed = $closeAutomatically ? false : ($request->has('is_closed') ? $request->boolean('is_closed') : $content->is_closed);

        // If we have a group, perform group sync/unlink
        if ($groupId) {
            $groupContents = ClassroomContent::where('group_id', $groupId)->get();
            $groupContentsByClass = $groupContents->keyBy('class_id');

            // Unlink any class that is NOT in the new classIds list
            foreach ($groupContents as $gContent) {
                if (!in_array($gContent->class_id, $classIds)) {
                    $gContent->update(['group_id' => null]);
                }
            }

            // Cache question images to avoid duplicate uploads
            $cachedQuestionImages = [];

            // Now loop over the desired classIds
            foreach ($classIds as $cId) {
                $contentData = [
                    'judul' => $request->judul,
                    'deskripsi' => $request->deskripsi,
                    'file_path' => $filePath,
                    'due_date' => $dueDate,
                    'is_closed' => $isClosed,
                    'close_automatically' => $closeAutomatically,
                    'difficulty' => $request->difficulty ?? $content->difficulty,
                    'weight' => $request->weight ?? $content->weight,
                    'estimated_duration' => $request->estimated_duration ?? $content->estimated_duration,
                    'quiz_duration_minutes' => $request->has('quiz_duration_minutes') ? $request->quiz_duration_minutes : $content->quiz_duration_minutes,
                    'quiz_max_attempts' => $request->has('quiz_max_attempts') ? $request->quiz_max_attempts : $content->quiz_max_attempts,
                    'allowed_file_types' => $request->allowed_file_types ?? $content->allowed_file_types,
                ];

                if ($groupContentsByClass->has($cId)) {
                    // Update existing group member
                    $targetContent = $groupContentsByClass->get($cId);
                    $targetContent->update($contentData);
                } else {
                    // Create new group member (new parallel class added during edit)
                    $targetContent = ClassroomContent::create(array_merge($contentData, [
                        'class_id' => $cId,
                        'subject_id' => $content->subject_id,
                        'teacher_id' => $content->teacher_id,
                        'group_id' => $groupId,
                        'tipe' => $content->tipe,
                    ]));
                }

                // Reconstruct quiz questions if tipe is kuis
                if ($content->tipe === 'kuis' && $request->filled('questions')) {
                    $questionsData = json_decode($request->questions, true);

                    if (is_array($questionsData)) {
                        // Delete old questions from db for this target content
                        $targetContent->questions()->delete();

                        // Insert new/updated ones
                        foreach ($questionsData as $index => $qData) {
                            $qImagePath = null;
                            $fileKey = "question_image_" . $index;

                            if (isset($cachedQuestionImages[$index])) {
                                $qImagePath = $cachedQuestionImages[$index];
                            } else if ($request->hasFile($fileKey)) {
                                $qImagePath = $request->file($fileKey)->store('quizzes', 'public');
                                $cachedQuestionImages[$index] = $qImagePath;
                            } else if (isset($qData['image_path']) && !empty($qData['image_path'])) {
                                // Preserve existing image if it is a relative path
                                $imagePathStr = $qData['image_path'];
                                $pos = strpos($imagePathStr, 'quizzes/');
                                if ($pos !== false) {
                                    $qImagePath = substr($imagePathStr, $pos);
                                    $cachedQuestionImages[$index] = $qImagePath;
                                }
                            }

                            QuizQuestion::create([
                                'content_id' => $targetContent->id,
                                'tipe_soal' => $qData['tipe_soal'] ?? 'pilihan_ganda',
                                'pertanyaan' => $qData['pertanyaan'],
                                'opsi_a' => $qData['opsi_a'],
                                'opsi_b' => $qData['opsi_b'],
                                'opsi_c' => $qData['opsi_c'],
                                'opsi_d' => $qData['opsi_d'],
                                'jawaban_benar' => $qData['jawaban_benar'],
                                'image_path' => $qImagePath,
                            ]);
                        }
                    }
                }
            }
        } else {
            // Fallback for single non-grouped content update
            $content->update([
                'judul' => $request->judul,
                'deskripsi' => $request->deskripsi,
                'file_path' => $filePath,
                'due_date' => $dueDate,
                'is_closed' => $isClosed,
                'close_automatically' => $closeAutomatically,
                'difficulty' => $request->difficulty ?? $content->difficulty,
                'weight' => $request->weight ?? $content->weight,
                'estimated_duration' => $request->estimated_duration ?? $content->estimated_duration,
                'quiz_duration_minutes' => $request->has('quiz_duration_minutes') ? $request->quiz_duration_minutes : $content->quiz_duration_minutes,
                'quiz_max_attempts' => $request->has('quiz_max_attempts') ? $request->quiz_max_attempts : $content->quiz_max_attempts,
                'allowed_file_types' => $request->allowed_file_types ?? $content->allowed_file_types,
            ]);

            // Reconstruct quiz questions if tipe is kuis
            if ($content->tipe === 'kuis' && $request->filled('questions')) {
                $questionsData = json_decode($request->questions, true);

                if (is_array($questionsData)) {
                    // Delete old question files from storage first
                    foreach ($content->questions as $q) {
                        if ($q->image_path) {
                            Storage::disk('public')->delete($q->image_path);
                        }
                    }
                    $content->questions()->delete();

                    foreach ($questionsData as $index => $qData) {
                        $qImagePath = null;
                        $fileKey = "question_image_" . $index;
                        if ($request->hasFile($fileKey)) {
                            $qImagePath = $request->file($fileKey)->store('quizzes', 'public');
                        } else if (isset($qData['image_path']) && !empty($qData['image_path'])) {
                            $imagePathStr = $qData['image_path'];
                            $pos = strpos($imagePathStr, 'quizzes/');
                            if ($pos !== false) {
                                $qImagePath = substr($imagePathStr, $pos);
                            }
                        }

                        QuizQuestion::create([
                            'content_id' => $content->id,
                            'tipe_soal' => $qData['tipe_soal'] ?? 'pilihan_ganda',
                            'pertanyaan' => $qData['pertanyaan'],
                            'opsi_a' => $qData['opsi_a'],
                            'opsi_b' => $qData['opsi_b'],
                            'opsi_c' => $qData['opsi_c'],
                            'opsi_d' => $qData['opsi_d'],
                            'jawaban_benar' => $qData['jawaban_benar'],
                            'image_path' => $qImagePath,
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Konten berhasil diperbarui',
            'data' => $content
        ], 200);
    }

    /**
     * Toggle the active closed status of a classroom content.
     * If the content belongs to a parallel group (has group_id),
     * the toggle is propagated to ALL contents in that group.
     */
    public function toggleClose($id)
    {
        $content = ClassroomContent::findOrFail($id);
        $newClosedState = !$content->is_closed;

        if ($content->group_id) {
            // Propagate toggle to all parallel class contents in the same group
            ClassroomContent::where('group_id', $content->group_id)
                ->update(['is_closed' => $newClosedState]);
        } else {
            // Single content (no group), only update this one
            $content->is_closed = $newClosedState;
            $content->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => $newClosedState ? 'Konten berhasil ditutup (semua kelas pararel)' : 'Konten berhasil dibuka kembali (semua kelas pararel)',
            'is_closed' => $newClosedState
        ]);
    }
}
