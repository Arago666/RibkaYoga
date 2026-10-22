<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_execution_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('practice_documents')->restrictOnDelete();
            $table->string('title');
            $table->string('mode', 16);
            $table->string('status', 16)->default('running');
            $table->string('timezone', 64)->default('UTC');
            $table->json('plan');
            $table->json('progress');
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'workspace_id', 'started_at'], 'execution_history');
        });
        Schema::create('sketchbook_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('page_format', 24)->default('square');
            $table->json('drawing_source')->nullable();
            $table->foreignUuid('image_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'archived_at']);
        });
        Schema::create('practice_native_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('image_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->json('drawing_source');
            $table->string('image_sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['workspace_id', 'image_asset_id']);
        });
        Schema::create('practice_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained('practice_documents')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->json('snapshot');
            $table->json('asset_ids');
            $table->boolean('allow_copy')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'document_id']);
        });
    }

    public function down(): void
    {
        // User documents and history are deliberately retained on rollback.
    }
};
