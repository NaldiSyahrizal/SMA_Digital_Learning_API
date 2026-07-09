<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classrooms')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('teacher_profiles')->onDelete('cascade');
            $table->enum('tipe', ['materi', 'tugas', 'kuis']);
            $table->string('judul');
            $table->text('deskripsi');
            $table->string('file_path')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_contents');
    }
};
