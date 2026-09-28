<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                // A row-local marker; it deliberately has no foreign key.
                $table->uuid('group_key')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('group_key');
            });
        }
    }
};
