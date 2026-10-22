<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('advance_mode', 16)->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->unsignedSmallInteger('breath_count')->nullable();
                $table->unsignedSmallInteger('repetitions')->nullable();
                $table->string('side', 16)->nullable();
                $table->string('side_order', 16)->nullable();
            });
        }
        Schema::table('practice_documents', function (Blueprint $table) {
            $table->string('fragment_kind', 16)->nullable();
        });
        Schema::table('practice_mesocycles', function (Blueprint $table) {
            $table->string('kind', 16)->default('cycle');
        });
        Schema::table('practice_mesocycle_weeks', function (Blueprint $table) {
            $table->string('title', 160)->nullable();
        });
        Schema::create('practice_personal_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'archived_at'], 'practice_personal_folders_scope');
        });
        Schema::create('practice_document_folders', function (Blueprint $table) {
            $table->foreignUuid('folder_id')->constrained('practice_personal_folders')->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained('practice_documents')->restrictOnDelete();
            $table->primary(['folder_id', 'document_id']);
        });
        Schema::create('practice_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 100);
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        // This migration adds user data. Roll back code without discarding it.
        throw new RuntimeException('Откат схемы запрещён: сохраните добавленные пользовательские данные.');
    }
};
