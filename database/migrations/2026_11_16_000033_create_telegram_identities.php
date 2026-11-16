<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->boolean('password_login_enabled')->default(true);
        });
        Schema::create('access_telegram_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('bot_id', 20);
            $table->string('telegram_id', 20);
            $table->string('username', 64)->nullable();
            $table->timestamps();
            $table->unique(['bot_id', 'telegram_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_telegram_identities');
        // Keep nullable email: Telegram-created accounts must remain intact on rollback.
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_login_enabled'));
    }
};
