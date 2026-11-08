<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add ghost type, locked flag, and created_by/updated_by to signatures
        Schema::table('wholemap_signatures', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('description');
            $table->string('created_by', 100)->nullable()->after('locked');
            $table->string('updated_by', 100)->nullable()->after('created_by');
        });

        // Add size to wormhole connections
        Schema::table('wormhole_connections', function (Blueprint $table) {
            $table->enum('size', ['small', 'medium', 'large', 'xlarge'])->default('large')->after('wormhole_type');
        });

        // Create system notes table
        Schema::create('wholemap_system_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('system_id');
            $table->string('system_name')->nullable();
            $table->enum('status', ['unknown', 'friendly', 'occupied', 'hostile'])->default('unknown');
            $table->string('comment', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'system_id']);
            $table->index('system_id');
        });

        // Create jump logs table
        Schema::create('wormhole_jump_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('wormhole_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('character_id')->nullable();
            $table->string('character_name', 100)->nullable();
            $table->unsignedInteger('ship_type_id')->nullable();
            $table->string('ship_type_name', 100)->nullable();
            $table->float('ship_mass')->nullable();
            $table->timestamp('jumped_at')->useCurrent();
            $table->timestamps();

            $table->index(['connection_id', 'jumped_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wormhole_jump_logs');
        Schema::dropIfExists('wholemap_system_notes');

        Schema::table('wormhole_connections', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('wholemap_signatures', function (Blueprint $table) {
            $table->dropColumn(['locked', 'created_by', 'updated_by']);
        });
    }
};
