<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password/request', [AuthController::class, 'forgotPasswordRequest']);
Route::post('/forgot-password/verify', [AuthController::class, 'forgotPasswordVerify']);
Route::post('/forgot-password/reset', [AuthController::class, 'forgotPasswordReset']);
Route::get('/dashboard', [DashboardController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/teacher/profile', [\App\Http\Controllers\Api\TeacherProfileController::class, 'show']);
    Route::put('/teacher/profile', [\App\Http\Controllers\Api\TeacherProfileController::class, 'update']);
    Route::post('/teacher/profile/photo', [\App\Http\Controllers\Api\TeacherProfileController::class, 'uploadPhoto']);
    
    // Teacher Classroom & Content APIs
    Route::get('/teacher/classes', [\App\Http\Controllers\Api\TeacherClassController::class, 'index']);
    Route::get('/teacher/classes/{classId}/students', [\App\Http\Controllers\Api\TeacherClassController::class, 'getStudents']);
    Route::get('/teacher/classes/{classId}/subjects/{subjectId}/export-grades', [\App\Http\Controllers\Api\TeacherClassController::class, 'exportGrades']);
    Route::get('/teacher/dashboard', [\App\Http\Controllers\Api\TeacherClassController::class, 'getDashboardStats']);
    
    // Principal APIs
    Route::get('/principal/dashboard', [\App\Http\Controllers\Api\PrincipalController::class, 'dashboard']);
    Route::get('/teacher/contents', [\App\Http\Controllers\Api\ClassroomContentController::class, 'index']);
    Route::get('/teacher/contents/{id}', [\App\Http\Controllers\Api\ClassroomContentController::class, 'show']);
    Route::post('/teacher/contents', [\App\Http\Controllers\Api\ClassroomContentController::class, 'store']);
    Route::put('/teacher/contents/{id}', [\App\Http\Controllers\Api\ClassroomContentController::class, 'update']);
    Route::post('/teacher/contents/{id}/toggle-close', [\App\Http\Controllers\Api\ClassroomContentController::class, 'toggleClose']);
    Route::delete('/teacher/contents/{id}', [\App\Http\Controllers\Api\ClassroomContentController::class, 'destroy']);
    Route::get('/teacher/contents/{id}/submissions', [\App\Http\Controllers\Api\ClassroomContentController::class, 'getSubmissions']);
    Route::post('/teacher/submissions/{id}/grade', [\App\Http\Controllers\Api\ClassroomContentController::class, 'gradeSubmission']);
    
    // Teacher Notifications APIs
    Route::get('/teacher/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/teacher/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::post('/teacher/notifications/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
    Route::post('/teacher/notifications/device-token', [\App\Http\Controllers\Api\NotificationController::class, 'updateDeviceToken']);
    Route::delete('/teacher/notifications/{id}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy']);
    Route::post('/teacher/notifications/delete-multiple', [\App\Http\Controllers\Api\NotificationController::class, 'deleteMultiple']);
    
    // Simulated Student Submission (Mock endpoint)
    Route::post('/student/submissions/simulate', [\App\Http\Controllers\Api\NotificationController::class, 'simulateSubmission']);

    // --- STUDENT MOBILE APP APIS ---
    Route::get('/student/dashboard', [\App\Http\Controllers\Api\StudentActivityController::class, 'dashboard']);
    Route::get('/student/subjects', [\App\Http\Controllers\Api\StudentActivityController::class, 'subjects']);
    Route::post('/student/subjects/{id}/interest', [\App\Http\Controllers\Api\StudentActivityController::class, 'saveInterest']);
    Route::get('/student/contents', [\App\Http\Controllers\Api\StudentActivityController::class, 'contents']);
    Route::get('/student/contents/{id}', [\App\Http\Controllers\Api\StudentActivityController::class, 'showContent']);
    Route::post('/student/submissions', [\App\Http\Controllers\Api\StudentActivityController::class, 'submitContent']);
    
    // Content & Submission
    Route::get('/contents/{id}', [\App\Http\Controllers\Api\ClassroomContentController::class, 'show']);
    Route::post('/contents/{id}/submit', [\App\Http\Controllers\Api\StudentActivityController::class, 'submitTask']);
    Route::post('/contents/{id}/submit-quiz', [\App\Http\Controllers\Api\StudentActivityController::class, 'submitQuiz']);
    
    // Forum Diskusi / Komentar
    Route::get('/contents/{id}/comments', [\App\Http\Controllers\Api\ContentCommentController::class, 'index']);
    Route::post('/contents/{id}/comments', [\App\Http\Controllers\Api\ContentCommentController::class, 'store']);
    Route::put('/contents/{id}/comments/{commentId}', [\App\Http\Controllers\Api\ContentCommentController::class, 'update']);
    Route::delete('/contents/{id}/comments/{commentId}', [\App\Http\Controllers\Api\ContentCommentController::class, 'destroy']);
    Route::delete('/student/submissions/{id}', [\App\Http\Controllers\Api\StudentActivityController::class, 'deleteSubmission']);
    Route::get('/student/profile', [\App\Http\Controllers\Api\StudentActivityController::class, 'profile']);
    Route::put('/student/profile', [\App\Http\Controllers\Api\StudentActivityController::class, 'updateProfile']);
    Route::post('/student/profile/photo', [\App\Http\Controllers\Api\StudentActivityController::class, 'uploadPhoto']);
    Route::get('/student/notifications', [\App\Http\Controllers\Api\StudentActivityController::class, 'notifications']);
});

// Master Data
Route::get('tingkatans', [\App\Http\Controllers\TingkatanController::class, 'index']);

// API Endpoints for Mapel, Paket, and Kelas
Route::apiResource('subjects', \App\Http\Controllers\SubjectController::class);
Route::apiResource('packages', \App\Http\Controllers\PackageController::class);
Route::apiResource('classrooms', \App\Http\Controllers\ClassroomController::class);
Route::get('teachers', [\App\Http\Controllers\ClassroomController::class, 'getTeachers']);
Route::apiResource('teachers-manage', \App\Http\Controllers\TeacherController::class);
Route::apiResource('students-manage', \App\Http\Controllers\StudentController::class);
Route::get('unassigned-students', [\App\Http\Controllers\Api\StudentClassroomController::class, 'unassignedStudents']);
Route::get('debug-ploting', [\App\Http\Controllers\Api\StudentClassroomController::class, 'debug']);
Route::apiResource('student-classrooms', \App\Http\Controllers\Api\StudentClassroomController::class);
Route::apiResource('teaching-assignments', \App\Http\Controllers\Api\TeachingAssignmentController::class);
