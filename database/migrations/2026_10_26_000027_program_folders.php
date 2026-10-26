<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_program_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'archived_at']);
        });
        foreach (['document' => 'practice_documents', 'cycle' => 'practice_mesocycles'] as $kind => $target) {
            Schema::create('practice_program_folder_'.$kind.'s', function (Blueprint $table) use ($kind, $target) {
                $table->foreignUuid('folder_id')->constrained('practice_program_folders')->restrictOnDelete();
                $table->foreignUuid($kind.'_id')->constrained($target)->restrictOnDelete();
                $table->primary(['folder_id', $kind.'_id']);
            });
        }
    }

    public function down(): void
    {
        // Personal folders and their contents are retained on rollback.
    }
};
