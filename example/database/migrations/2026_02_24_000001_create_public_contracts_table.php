<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->primary();
            $table->unsignedInteger('region_id')->index();
            $table->string('type', 30)->index();
            $table->string('title')->nullable();
            $table->boolean('for_corporation')->default(false);
            $table->unsignedBigInteger('issuer_corporation_id')->nullable();
            $table->unsignedBigInteger('issuer_id')->nullable();
            $table->timestamp('date_issued')->nullable();
            $table->timestamp('date_expired')->nullable();
            $table->unsignedInteger('days_to_complete')->default(0);
            $table->unsignedBigInteger('start_location_id')->nullable();
            $table->unsignedBigInteger('end_location_id')->nullable();
            $table->double('price')->default(0);
            $table->double('buyout')->nullable();
            $table->double('reward')->nullable();
            $table->double('collateral')->nullable();
            $table->double('volume')->default(0);
            $table->timestamps();
        });

        Schema::create('public_contract_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('contract_id')->index();
            $table->unsignedInteger('type_id')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_included')->default(true);
            $table->boolean('is_blueprint_copy')->nullable();
            $table->unsignedSmallInteger('material_efficiency')->nullable();
            $table->unsignedSmallInteger('time_efficiency')->nullable();
            $table->integer('runs')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->timestamps();

            $table->foreign('contract_id')
                ->references('contract_id')
                ->on('public_contracts')
                ->onDelete('cascade');

            $table->index(['contract_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_contract_items');
        Schema::dropIfExists('public_contracts');
    }
};
