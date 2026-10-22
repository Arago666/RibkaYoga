<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_card_snapshots', function (Blueprint $table) {
            $table->json('classification_snapshot')->nullable();
        });
    }

    public function down(): void {}
};
