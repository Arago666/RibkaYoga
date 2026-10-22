<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_publications', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->json('options')->nullable();
        });
        Schema::table('practice_document_shares', function (Blueprint $table) {
            $table->boolean('allow_copy')->default(false);
        });
        Schema::create('practice_output_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('preferences');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void {}
};
