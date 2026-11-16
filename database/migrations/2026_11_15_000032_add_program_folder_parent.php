<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_program_folders', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->index();
            $table->foreign('parent_id')->references('id')->on('practice_program_folders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('practice_program_folders', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
