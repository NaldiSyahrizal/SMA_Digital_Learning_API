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
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->string('tipe_soal')->default('pilihan_ganda')->after('pertanyaan');
            $table->string('opsi_a')->nullable()->change();
            $table->string('opsi_b')->nullable()->change();
            $table->string('opsi_c')->nullable()->change();
            $table->string('opsi_d')->nullable()->change();
            $table->char('jawaban_benar', 1)->nullable()->change();
        });

        Schema::table('student_submissions', function (Blueprint $table) {
            $table->json('quiz_answers')->nullable()->after('submission_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_submissions', function (Blueprint $table) {
            $table->dropColumn('quiz_answers');
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn('tipe_soal');
            $table->string('opsi_a')->nullable(false)->change();
            $table->string('opsi_b')->nullable(false)->change();
            $table->string('opsi_c')->nullable(false)->change();
            $table->string('opsi_d')->nullable(false)->change();
            $table->char('jawaban_benar', 1)->nullable(false)->change();
        });
    }
};
