<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One lock per workspace serializes changes to the tree, including an empty tree.
        Schema::create('collection_folder_trees', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->primary()->constrained('workspaces')->restrictOnDelete();
        });
        Schema::create('collection_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 160);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'parent_id']);
        });
        // PostgreSQL requires the UUID primary key before adding a self-reference.
        Schema::table('collection_folders', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('collection_folders')->restrictOnDelete();
        });
        Schema::table('asana_collections', function (Blueprint $table) {
            $table->foreignUuid('folder_id')->nullable()->constrained('collection_folders')->restrictOnDelete();
            $table->index(['workspace_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('asana_collections', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'folder_id']);
            $table->dropConstrainedForeignId('folder_id');
        });
        Schema::dropIfExists('collection_folders');
        Schema::dropIfExists('collection_folder_trees');
    }
};
