<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sketchbook_image_owners', function (Blueprint $table) {
            $table->foreignUuid('image_asset_id')->primary()->constrained('media_assets')->restrictOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        DB::table('sketchbook_pages')->whereNotNull('image_asset_id')->orderBy('id')->chunk(100, function ($pages) {
            foreach ($pages as $page) {
                DB::table('sketchbook_image_owners')->insertOrIgnore(['image_asset_id' => $page->image_asset_id, 'workspace_id' => $page->workspace_id, 'user_id' => $page->user_id, 'created_at' => now()]);
            }
        });
    }

    public function down(): void {}
};
