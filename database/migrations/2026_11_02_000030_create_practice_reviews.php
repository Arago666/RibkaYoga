<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('source_document_id')->constrained('practice_documents')->restrictOnDelete();
            $table->unsignedInteger('source_revision');
            $table->string('title', 160);
            $table->json('snapshot');
            $table->json('annotations');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'archived_at'], 'practice_reviews_scope');
        });
        Schema::create('practice_review_assets', function (Blueprint $table) {
            $table->foreignUuid('review_id')->constrained('practice_reviews')->restrictOnDelete();
            $table->foreignUuid('image_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->primary(['review_id', 'image_asset_id']);
        });
    }

    public function down(): void
    {
        // Saved analyses and their pinned images are retained if application code is rolled back.
    }
};
