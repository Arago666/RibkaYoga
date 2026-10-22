<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_audio_libraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unique(['workspace_id', 'owner_id']);
        });
        Schema::create('media_audio_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('original_filename');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('mime', 64);
            $table->string('codec', 40);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('duration_ms');
            $table->string('sha256', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'owner_id', 'archived_at']);
        });
        Schema::create('meditation_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->json('settings');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'owner_id']);
        });
        Schema::create('meditation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('status', 24);
            $table->string('timezone', 80);
            $table->json('settings');
            $table->foreignUuid('audio_asset_id')->nullable()->constrained('media_audio_assets')->restrictOnDelete();
            $table->unsignedBigInteger('active_ms')->default(0);
            $table->timestamp('started_at', 3);
            $table->timestamp('active_since', 3)->nullable();
            $table->timestamp('lease_until', 3)->nullable();
            $table->timestamp('ended_at', 3)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['workspace_id', 'owner_id', 'started_at']);
        });
    }

    public function down(): void
    {
        // Personal audio and session history must survive an application rollback.
    }
};
