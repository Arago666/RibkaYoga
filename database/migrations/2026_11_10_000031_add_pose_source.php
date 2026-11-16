<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_asanas', fn (Blueprint $table) => $table->json('pose_source')->nullable());
    }

    public function down(): void
    {
        Schema::table('catalog_asanas', fn (Blueprint $table) => $table->dropColumn('pose_source'));
    }
};
