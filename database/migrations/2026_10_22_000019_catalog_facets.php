<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->string('dimension', 20)->nullable();
            $table->string('system_key', 80)->nullable();
            $table->unique(['workspace_id', 'system_key'], 'catalog_category_system_unique');
            $table->index(['workspace_id', 'dimension', 'sort_order'], 'catalog_category_dimension_index');
        });
        Schema::table('catalog_asanas', function (Blueprint $table) {
            $table->string('sanskrit_cyrillic', 160)->nullable();
            $table->string('sanskrit_latin', 160)->nullable();
            $table->json('steps')->nullable();
            $table->json('teacher_cues')->nullable();
            $table->json('equipment')->nullable();
            $table->json('precautions')->nullable();
            $table->json('adaptations')->nullable();
            $table->json('editorial')->nullable();
            $table->boolean('has_bind')->nullable();
            $table->string('classification_status', 20)->default('unreviewed');
        });
        Schema::create('catalog_asana_preferences', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->cascadeOnDelete();
            $table->boolean('favorite')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->primary(['workspace_id', 'user_id', 'asana_id'], 'catalog_asana_preference_primary');
            $table->index(['workspace_id', 'user_id', 'last_used_at'], 'catalog_asana_preference_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_asana_preferences');
        Schema::table('catalog_asanas', function (Blueprint $table) {
            $table->dropColumn(['sanskrit_cyrillic', 'sanskrit_latin', 'steps', 'teacher_cues', 'equipment', 'precautions', 'adaptations', 'editorial', 'has_bind', 'classification_status']);
        });
        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropUnique('catalog_category_system_unique');
            $table->dropIndex('catalog_category_dimension_index');
            $table->dropColumn(['dimension', 'system_key']);
        });
    }
};
