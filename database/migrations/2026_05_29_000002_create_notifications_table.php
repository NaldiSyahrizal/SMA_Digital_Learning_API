<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id'); // target teacher
            $table->string('type'); // 'plotting', 'submission', 'profile_update'
            $table->text('message');
            $table->text('data')->nullable(); // json string for navigation data (class_id, subject_id, content_id, etc.)
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->foreign('teacher_id')->references('id')->on('teacher_profiles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
