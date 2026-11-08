<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ess_payout_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ess_tracked_system_id');
            $table->timestamp('occurred_at');
            $table->decimal('amount_isk', 20, 2)->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('ess_tracked_system_id')
                ->references('id')
                ->on('ess_tracked_systems')
                ->cascadeOnDelete();

            $table->index(['ess_tracked_system_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ess_payout_events');
    }
};
