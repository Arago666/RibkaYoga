<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalog_categories')->whereNotNull('parent_id')->update([
            'parent_id' => null,
            'revision' => DB::raw('revision + 1'),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Former parent relationships cannot be inferred from flat categories.
        // Keep categories and their assignments intact when rolling back code.
    }
};
