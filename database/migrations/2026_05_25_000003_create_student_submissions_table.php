<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('classroom_contents')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('student_profiles')->onDelete('cascade');
            $table->text('submission_text')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('nilai')->nullable();
            $table->text('catatan')->nullable();
            $table->enum('status', ['submitted', 'graded'])->default('submitted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_submissions');
    }
};
