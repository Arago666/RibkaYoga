<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE COLLATION IF NOT EXISTS ribka_ru (provider = icu, locale = 'ru', deterministic = true)");
        }
        Schema::create('catalog_asanas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 160);
            $table->text('normalized_search_text');
            $table->text('description')->nullable();
            $table->foreignUuid('current_image_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'archived_at', 'normalized_name']);
        });
        Schema::create('catalog_asana_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 160);
        });
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['workspace_id', 'parent_id']);
        });
        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('catalog_categories')->restrictOnDelete();
        });
        Schema::create('catalog_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('name', 60);
            $table->string('normalized_name', 60);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'normalized_name']);
        });
        Schema::create('catalog_asana_category', function (Blueprint $table) {
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('catalog_categories')->restrictOnDelete();
            $table->primary(['asana_id', 'category_id']);
        });
        Schema::create('catalog_asana_tag', function (Blueprint $table) {
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('catalog_tags')->cascadeOnDelete();
            $table->primary(['asana_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        foreach (['catalog_asana_tag', 'catalog_asana_category', 'catalog_tags', 'catalog_categories', 'catalog_asana_aliases', 'catalog_asanas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
