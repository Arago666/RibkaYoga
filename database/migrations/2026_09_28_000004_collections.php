<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asana_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'archived_at']);
        });
        Schema::create('asana_collection_items', function (Blueprint $table) {
            $table->foreignUuid('collection_id')->constrained('asana_collections')->cascadeOnDelete();
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->primary(['collection_id', 'asana_id']);
            $table->unique(['collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_collection_items');
        Schema::dropIfExists('asana_collections');
    }
};
