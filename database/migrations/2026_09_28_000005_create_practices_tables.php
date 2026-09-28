<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_card_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('source_asana_id')->nullable()->constrained('catalog_asanas')->nullOnDelete();
            $table->string('name');
            $table->foreignUuid('image_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'source_asana_id']);
            $table->index('image_asset_id');
        });

        Schema::create('practice_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('kind', 16)->default('practice');
            $table->string('title');
            $table->string('theme')->nullable();
            $table->text('note')->nullable();
            $table->date('practice_date')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'kind', 'archived_at', 'updated_at'], 'practice_documents_listing');
        });

        Schema::create('practice_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('practice_documents')->cascadeOnDelete();
            $table->string('title');
            $table->string('purpose')->nullable();
            $table->unsignedSmallInteger('position');
            $table->index(['document_id', 'position']);
        });
        Schema::create('practice_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('block_id')->constrained('practice_blocks')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('position');
            $table->index(['block_id', 'position']);
        });
        Schema::create('practice_cells', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('row_id')->constrained('practice_rows')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUuid('card_snapshot_id')->nullable()->constrained('practice_card_snapshots')->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->index(['row_id', 'position']);
            $table->index('card_snapshot_id');
        });

        Schema::create('practice_row_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('title');
            $table->string('theme')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'archived_at', 'updated_at'], 'practice_row_templates_listing');
        });
        Schema::create('practice_row_template_cells', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('row_template_id')->constrained('practice_row_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUuid('card_snapshot_id')->nullable()->constrained('practice_card_snapshots')->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->index(['row_template_id', 'position']);
            $table->index('card_snapshot_id');
        });

        // Retain UUID ownership after removing a row/cell, so undo can recreate it
        // in the same document without allowing another document to adopt its ID.
        Schema::create('practice_entity_ids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('owner_id');
            $table->string('owner_type', 16);
            $table->string('entity_type', 16);
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_entity_ids');
        Schema::dropIfExists('practice_row_template_cells');
        Schema::dropIfExists('practice_row_templates');
        Schema::dropIfExists('practice_cells');
        Schema::dropIfExists('practice_rows');
        Schema::dropIfExists('practice_blocks');
        Schema::dropIfExists('practice_documents');
        Schema::dropIfExists('practice_card_snapshots');
    }
};
