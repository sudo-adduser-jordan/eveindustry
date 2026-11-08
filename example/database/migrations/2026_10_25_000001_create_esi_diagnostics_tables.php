<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Short-lived investigation detail: upstream failures and circuit
        // transitions only. No payload blobs, URLs, headers or tokens.
        Schema::create('esi_diagnostic_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('event_id', 26)->unique();
            $table->timestamp('occurred_at', 6);
            $table->string('kind', 24);
            $table->string('method', 7)->nullable();
            $table->string('operation_key', 160)->default('');
            $table->string('compatibility_date', 10)->nullable();
            $table->string('dataset', 48)->nullable();
            $table->string('job_class', 96)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('source_identity', 48)->default('');
            $table->string('application_id', 48)->default('');
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->unsignedSmallInteger('batch_size')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('reason', 32)->default('unknown');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('response_received')->default(false);
            $table->smallInteger('legacy_remaining')->nullable();
            $table->unsignedInteger('legacy_reset_seconds')->nullable();
            $table->timestamp('legacy_reset_at')->nullable();
            $table->string('limiter_group', 64)->nullable();
            $table->string('token_limit', 32)->nullable();
            $table->unsignedInteger('token_used')->nullable();
            $table->integer('token_remaining')->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->string('upstream_request_id', 64)->nullable();
            $table->char('batch_fingerprint', 64)->nullable();
            $table->string('release', 40)->nullable();

            $table->index(['occurred_at', 'id']);
            $table->index(['operation_key', 'http_status', 'occurred_at'], 'esi_events_operation_status_idx');
            $table->index(['character_id', 'occurred_at']);
        });

        // Hourly endpoint trends. dimension_hash covers the normalized
        // dimensions with explicit sentinels, so the unique key never depends
        // on nullable columns.
        Schema::create('esi_endpoint_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('dimension_hash', 64);
            $table->timestamp('bucket_at');
            $table->string('operation_key', 160)->default('');
            $table->string('event_kind', 24);
            $table->unsignedSmallInteger('http_status')->default(0);
            $table->string('reason', 32)->default('unknown');
            $table->string('dataset', 48)->default('');
            $table->string('source_identity', 48)->default('');
            $table->string('limiter_group', 64)->default('');
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('success_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->unsignedBigInteger('duration_sum_ms')->default(0);
            $table->unsignedBigInteger('duration_count')->default(0);
            $table->unsignedInteger('duration_max_ms')->default(0);
            $table->timestamp('first_observed_at', 6)->nullable();
            $table->timestamp('last_observed_at', 6)->nullable();

            $table->unique(['dimension_hash', 'bucket_at'], 'esi_endpoint_metrics_unique');
            $table->index('bucket_at');
            $table->index(['operation_key', 'bucket_at']);
        });

        // Minute (and hourly summary) budget observations.
        Schema::create('esi_budget_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('dimension_hash', 64);
            $table->timestamp('bucket_at');
            $table->string('granularity', 6);
            $table->string('source_identity', 48)->default('');
            $table->string('application_id', 48)->default('');
            $table->string('mechanism', 8);
            $table->string('limiter_group', 64)->default('');
            $table->unsignedInteger('observations')->default(0);
            $table->integer('remaining_min')->nullable();
            $table->integer('remaining_max')->nullable();
            $table->integer('remaining_first')->nullable();
            $table->integer('remaining_last')->nullable();
            $table->unsignedBigInteger('used_sum')->nullable();
            $table->timestamp('reset_at_min')->nullable();
            $table->timestamp('reset_at_max')->nullable();
            $table->unsignedInteger('circuit_opened_count')->default(0);
            $table->unsignedInteger('circuit_closed_count')->default(0);
            $table->unsignedInteger('pause_seconds')->default(0);

            $table->unique(['dimension_hash', 'bucket_at'], 'esi_budget_metrics_unique');
            $table->index(['granularity', 'bucket_at']);
        });

        Schema::create('esi_telemetry_checkpoints', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->string('stream_epoch', 64)->nullable();
            $table->string('last_stream_id', 32)->nullable();
            $table->timestamp('last_flushed_at', 6)->nullable();
            $table->unsignedInteger('gap_count')->default(0);
            $table->timestamp('last_gap_at', 6)->nullable();
            $table->string('last_gap_reason', 48)->nullable();
            $table->unsignedBigInteger('dropped_events')->default(0);
            $table->unsignedBigInteger('expired_rows')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esi_telemetry_checkpoints');
        Schema::dropIfExists('esi_budget_metrics');
        Schema::dropIfExists('esi_endpoint_metrics');
        Schema::dropIfExists('esi_diagnostic_events');
    }
};
