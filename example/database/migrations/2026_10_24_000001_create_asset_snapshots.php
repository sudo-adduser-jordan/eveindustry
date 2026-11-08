<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_snapshot_owners', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('generation')->default(0);
            $table->unsignedBigInteger('active_snapshot_id')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
            $table->index('next_due_at');
        });
        Schema::create('asset_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('asset_snapshot_owners')->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->string('status', 16)->default('building');
            $table->unsignedInteger('item_count')->default(0);
            $table->boolean('enrichment_pending')->default(false);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('source_expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'generation']);
            $table->index(['owner_id', 'status']);
        });
        Schema::create('asset_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('asset_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('type_id');
            $table->bigInteger('quantity');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('parent_item_id')->nullable();
            $table->unsignedBigInteger('root_item_id');
            $table->unsignedBigInteger('root_location_id');
            $table->unsignedBigInteger('solar_system_id')->nullable();
            $table->string('solar_system_name');
            $table->string('type_name');
            $table->string('name')->nullable();
            $table->string('location_name');
            $table->string('character_name');
            $table->string('owner_type', 16);
            $table->json('payload');
            $table->json('raw_payload');
            $table->unique(['snapshot_id', 'item_id']);
            $table->index(['snapshot_id', 'parent_item_id']);
            $table->index(['snapshot_id', 'root_item_id']);
            $table->index(['snapshot_id', 'root_location_id', 'parent_item_id'], 'asset_items_snapshot_location_parent_idx');
            $table->index(['snapshot_id', 'type_name']);
            $table->index(['snapshot_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_snapshot_items');
        Schema::dropIfExists('asset_snapshots');
        Schema::dropIfExists('asset_snapshot_owners');
    }
};
