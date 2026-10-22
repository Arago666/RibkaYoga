<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_documents', function (Blueprint $table) {
            $table->string('illustration_style', 24)->nullable();
            $table->string('illustration_character', 24)->nullable();
        });
        Schema::create('catalog_illustration_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('style', 24)->default('original');
            $table->string('character', 24)->default('neutral');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'owner_id']);
        });
        Schema::create('catalog_illustration_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->restrictOnDelete();
            $table->foreignUuid('source_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->string('source_sha256', 64);
            $table->foreignUuid('image_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->string('pose_key', 40);
            $table->string('style', 24);
            $table->string('character', 24);
            $table->unsignedInteger('model_revision');
            $table->json('render_source');
            $table->json('drawing_source')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'asana_id', 'source_asset_id', 'style', 'character', 'model_revision'], 'catalog_illustration_exact_unique');
        });
    }

    public function down(): void
    {
        // User snapshots and preferences remain intact on an application rollback.
    }
};
