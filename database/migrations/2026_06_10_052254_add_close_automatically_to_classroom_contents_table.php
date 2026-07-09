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
        Schema::table('classroom_contents', function (Blueprint $table) {
            $table->boolean('close_automatically')->default(false)->after('is_closed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classroom_contents', function (Blueprint $table) {
            $table->dropColumn('close_automatically');
        });
    }
};
