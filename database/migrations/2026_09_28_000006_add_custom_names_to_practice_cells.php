<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('custom_name', 160)->nullable();
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_content_exclusive CHECK (card_snapshot_id IS NULL OR custom_name IS NULL)");
            }
        }
    }

    public function down(): void
    {
        foreach (['practice_cells', 'practice_row_template_cells'] as $tableName) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$tableName}_content_exclusive");
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('custom_name');
            });
        }
    }
};
