<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_monitor_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->boolean('active_discovery')->default(false);
            $table->boolean('discord_alerts')->default(false);
            $table->unsignedSmallInteger('interval_seconds')->default(60);
            $table->unsignedSmallInteger('probes_per_round')->default(16);
            $table->unsignedSmallInteger('retention_days')->default(90);
        });

        DB::table('network_monitor_settings')->insert(['id' => 1]);

        Schema::create('network_devices', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100)->nullable();
            $table->string('mac', 17)->nullable()->unique();
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 45)->nullable();
            $table->boolean('monitor')->default(true);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
        });

        Schema::create('network_sources', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('kind', 12);
            $table->foreignId('device_id')->nullable()->unique()->constrained('network_devices')->cascadeOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('last_report_at')->nullable();
            $table->uuid('session')->nullable();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->unsignedBigInteger('rx_bytes')->nullable();
            $table->unsignedBigInteger('tx_bytes')->nullable();
        });

        Schema::create('network_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->timestamp('observed_at')->index();
            $table->boolean('reachable');
            $table->string('source', 8);
            $table->unsignedInteger('latency_us')->nullable();
            $table->index(['device_id', 'observed_at']);
        });

        Schema::create('network_traffic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->timestamp('observed_at')->index();
            $table->unsignedInteger('elapsed_seconds');
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->index(['device_id', 'observed_at']);
        });

        Schema::create('network_connectivity', function (Blueprint $table) {
            $table->id();
            $table->timestamp('observed_at')->index();
            $table->string('internet', 12);
            $table->string('router', 12);
            $table->string('upnp', 12);
            $table->boolean('ipv4');
            $table->boolean('ipv6');
            $table->boolean('ping');
        });

        Schema::create('network_outages', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamp('interrupted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['outages', 'connectivity', 'traffic', 'presence', 'sources', 'devices', 'monitor_settings'] as $table) {
            Schema::dropIfExists('network_'.$table);
        }
    }
};
