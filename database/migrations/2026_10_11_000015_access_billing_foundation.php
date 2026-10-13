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
            $table->boolean('is_admin')->default(false);
            $table->string('phone', 64)->nullable();
            $table->string('telegram', 120)->nullable();
            $table->text('requisites')->nullable();
            $table->string('avatar_path')->nullable();
        });
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained('workspaces')->restrictOnDelete();
            $table->string('plan', 32);
            $table->string('status', 24);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('billing_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('details');
            $table->timestamps();
        });
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('provider', 64);
            $table->string('provider_payment_id')->unique();
            $table->string('status', 24);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('RUB');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        // Existing spaces keep full access; this is a manual grant, never a payment.
        DB::table('workspaces')->select('id')->orderBy('id')->chunk(100, function ($workspaces) {
            foreach ($workspaces as $workspace) {
                DB::table('billing_subscriptions')->insert([
                    'id' => (string) Str::uuid(), 'workspace_id' => $workspace->id,
                    'plan' => 'pro', 'status' => 'active', 'starts_at' => now(), 'ends_at' => null,
                    'cancel_at_period_end' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_subscription_events');
        Schema::dropIfExists('billing_subscriptions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'phone', 'telegram', 'requisites', 'avatar_path']));
    }
};
