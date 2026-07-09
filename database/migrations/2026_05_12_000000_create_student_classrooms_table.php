<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_classrooms', function (Blueprint $豊) {
            $豊->id();
            $豊->foreignId('student_id')->constrained('student_profiles')->onDelete('cascade');
            $豊->foreignId('class_id')->constrained('classrooms')->onDelete('cascade');
            $豊->timestamps();
            
            // Prevent duplicate plotting for same student in same class
            $豊->unique(['student_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_classrooms');
    }
};
