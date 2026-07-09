<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add SAW and Quiz attributes to classroom_contents table
        Schema::table('classroom_contents', function (Blueprint $table) {
            $table->integer('difficulty')->default(3)->comment('Range 1-5: Sangat Mudah s/d Sangat Sulit');
            $table->integer('weight')->default(10)->comment('Range 1-100: Bobot Kepentingan Tugas');
            $table->integer('estimated_duration')->default(2)->comment('1=Sangat Cepat, 2=Cepat, 3=Sedang, 5=Lama');
            $table->integer('quiz_duration_minutes')->nullable()->comment('Batas waktu pengerjaan kuis');
            $table->integer('quiz_max_attempts')->default(1)->comment('Maksimal percobaan pengerjaan kuis (0/null = tanpa batas)');
            $table->enum('allowed_file_types', ['pdf', 'image', 'all'])->default('all')->comment('Pembatasan format file tugas');
        });

        // 2. Add attempt_number to student_submissions table to support multiple quiz attempts
        Schema::table('student_submissions', function (Blueprint $table) {
            $table->integer('attempt_number')->default(1)->comment('Pengerjaan kuis/tugas ke-berapa');
        });

        // 3. Create student_subject_interests table to store personalized subject interests
        Schema::create('student_subject_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->integer('interest_score')->default(3)->comment('Range 1-5: Minat siswa terhadap mapel');
            $table->timestamps();

            // Unique combination to prevent duplicate ratings per student and subject
            $table->unique(['student_id', 'subject_id']);
        });
        // 4. Add jam_pelajaran to subjects table
        Schema::table('subjects', function (Blueprint $table) {
            $table->integer('jam_pelajaran')->default(3)->comment('Jumlah Jam Pelajaran per minggu (1-10)');
        });
    }

    public function down(): void
    {
        // 1. Drop jam_pelajaran from subjects table
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('jam_pelajaran');
        });

        // 2. Drop student_subject_interests table
        Schema::dropIfExists('student_subject_interests');

        // 3. Remove attempt_number from student_submissions table
        Schema::table('student_submissions', function (Blueprint $table) {
            $table->dropColumn('attempt_number');
        });

        // 4. Remove SAW and Quiz attributes from classroom_contents table
        Schema::table('classroom_contents', function (Blueprint $table) {
            $table->dropColumn([
                'difficulty',
                'weight',
                'estimated_duration',
                'quiz_duration_minutes',
                'quiz_max_attempts',
                'allowed_file_types'
            ]);
        });
    }
};
