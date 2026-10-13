<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_transition_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('transition_id')->constrained('practice_row_templates')->cascadeOnDelete();
            $table->foreignUuid('related_transition_id')->constrained('practice_row_templates')->restrictOnDelete();
            $table->string('kind', 16);
            $table->unique(['transition_id', 'related_transition_id', 'kind'], 'practice_transition_relations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_transition_relations');
    }
};
