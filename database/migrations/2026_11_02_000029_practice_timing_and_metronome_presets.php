<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_documents', function (Blueprint $table) {
            $table->unsignedInteger('default_duration_seconds')->nullable();
            $table->unsignedInteger('default_rest_seconds')->nullable();
        });
        foreach (['practice_cells', 'practice_row_template_cells'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('rest_seconds')->nullable();
            });
        }
        Schema::create('practice_metronome_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->json('settings');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'archived_at'], 'practice_metronome_presets_scope');
        });
    }

    public function down(): void
    {
        // Retain saved timing and preset data if application code is rolled back.
    }
};
