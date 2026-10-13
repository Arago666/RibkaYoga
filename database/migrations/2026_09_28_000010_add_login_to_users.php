<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login', 64)->nullable()->unique();
        });

        DB::table('users')->select('id', 'name')->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $base = substr(Str::slug($user->name, '_'), 0, 40) ?: 'user_'.$user->id;
                $login = $base;
                $suffix = 0;
                while (DB::table('users')->where('login', $login)->exists()) {
                    $login = $base.'_'.++$suffix;
                }
                DB::table('users')->where('id', $user->id)->update(['login' => $login]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('login', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login']);
            $table->dropColumn('login');
        });
    }
};
