<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_asanas', function (Blueprint $table) {
            $table->string('import_key', 190)->nullable();
            $table->string('import_source', 255)->nullable();
            $table->unique(['workspace_id', 'import_key']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_asanas', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'import_key']);
            $table->dropColumn(['import_key', 'import_source']);
        });
    }
};
