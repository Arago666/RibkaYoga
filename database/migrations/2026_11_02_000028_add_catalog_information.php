<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_asanas', function (Blueprint $table) {
            $table->string('alternate_name', 160)->nullable();
            $table->text('information_description')->nullable();
            $table->json('benefits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_asanas', function (Blueprint $table) {
            $table->dropColumn(['alternate_name', 'information_description', 'benefits']);
        });
    }
};
