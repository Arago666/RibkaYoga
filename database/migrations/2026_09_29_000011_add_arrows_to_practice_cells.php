<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('arrow_direction', 5)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('arrow_direction'));
        }
    }
};
