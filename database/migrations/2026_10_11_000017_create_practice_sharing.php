<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_document_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('practice_documents')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('permission', 16);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'recipient_user_id']);
            $table->index(['recipient_user_id', 'revoked_at']);
        });
        Schema::create('practice_document_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('practice_documents')->restrictOnDelete();
            $table->foreignUuid('share_id')->constrained('practice_document_shares')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            // The context survives replacement/removal of a cell in a later document revision.
            $table->uuid('cell_id')->nullable();
            $table->string('context', 300)->nullable();
            $table->unsignedInteger('document_revision');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'share_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_document_comments');
        Schema::dropIfExists('practice_document_shares');
    }
};
