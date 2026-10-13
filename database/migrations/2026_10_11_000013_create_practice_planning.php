<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_row_templates', function (Blueprint $table) {
            $table->string('kind', 16)->default('row');
        });
        Schema::create('practice_mesocycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('title');
            $table->string('theme')->nullable();
            $table->json('cloud_asana_ids');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'archived_at', 'updated_at'], 'practice_mesocycles_listing');
        });
        Schema::create('practice_mesocycle_weeks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mesocycle_id')->constrained('practice_mesocycles')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('practice_documents')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unique(['mesocycle_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_mesocycle_weeks');
        Schema::dropIfExists('practice_mesocycles');
        Schema::table('practice_row_templates', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
