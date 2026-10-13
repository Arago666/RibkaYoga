<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_asana_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('asana_id')->constrained('catalog_asanas')->cascadeOnDelete();
            $table->foreignUuid('related_asana_id')->constrained('catalog_asanas')->restrictOnDelete();
            $table->string('kind', 20);
            $table->unique(['asana_id', 'related_asana_id', 'kind'], 'catalog_asana_relation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_asana_relations');
    }
};
