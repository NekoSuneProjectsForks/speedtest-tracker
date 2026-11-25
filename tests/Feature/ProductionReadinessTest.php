<?php

use App\Actions\Influxdb\v2\BuildPointData;
use App\Enums\ResultStatus;
use App\Enums\UserRole;
use App\Models\Result;
use App\Models\User;
use App\Services\NetworkMonitor\Monitor;
use App\Settings\DataIntegrationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('public REST and exporters omit private fields even with private history present', function () {
    $private = ['198.51.100.70', '192.0.2.76', '2001:db8::76', 'Private ISP', 'Private City', 'Private Device', '02:11:22:33:44:56', 'private-host.example.test'];
    $result = Result::factory()->create([
        'status' => ResultStatus::Completed,
        'ping' => 20,
        'download' => 1000000,
        'upload' => 500000,
        'data' => [
            'isp' => $private[3],
            'server' => ['ip' => $private[2], 'location' => $private[4], 'host' => $private[7]],
            'interface' => ['externalIp' => $private[0], 'internalIp' => $private[1], 'macAddr' => $private[6]],
            'ping' => ['jitter' => 1.2],
        ],
        'comments' => $private[5],
    ]);
    DB::table('network_devices')->insert(['label' => $private[5], 'mac' => $private[6], 'ipv4' => $private[1], 'ipv6' => $private[2]]);
    app(DataIntegrationSettings::class)->fill(['prometheus_enabled' => true, 'prometheus_allowed_ips' => []])->save();
    Cache::forever('prometheus:latest_result', $result->id);

    foreach (['/api/speedtest/latest', '/api/v2/latest', '/api/v2/history', '/api/v2/stats', '/api/v2/health', '/api/healthcheck', '/prometheus'] as $route) {
        $response = $this->getJson($route)->assertOk();
        foreach ($private as $value) {
            $response->assertDontSee($value, false);
        }
    }

    $this->getJson('/api/v2/latest')->assertExactJson([
        'data' => [
            'id' => $result->id, 'timestamp' => $result->created_at->toISOString(),
            'download_mbps' => 8, 'upload_mbps' => 4, 'ping_ms' => 20, 'jitter_ms' => 1.2, 'packet_loss_pct' => null,
        ],
    ]);
    $this->get('/prometheus')->assertSee('speedtest_tracker_download_bits', false);
    $line = BuildPointData::run($result)->toLineProtocol();
    expect($line)->toStartWith('speedtest_anonymous,')->toContain('download_bits=8000000i');
    foreach ($private as $value) {
        expect($line)->not->toContain($value);
    }
    $this->getJson('/api/v1/results/'.$result->id)->assertUnauthorized();
});

test('public history paginates backward without duplicates after new results arrive', function () {
    $stamp = now()->subHour();
    $results = Result::factory()->count(5)->create([
        'status' => ResultStatus::Completed, 'created_at' => $stamp, 'data' => [],
    ]);
    Result::factory()->create(['status' => ResultStatus::Failed, 'created_at' => $stamp]);
    Result::factory()->create(['status' => ResultStatus::Completed, 'created_at' => now()->subDays(4)]);
    $cursor = null;
    $seen = [];
    do {
        $query = array_filter(['days' => 1, 'limit' => 2, 'before_id' => $cursor]);
        $response = $this->getJson('/api/v2/history?'.http_build_query($query))->assertOk();
        if ($cursor === null) {
            Result::factory()->create(['status' => ResultStatus::Completed, 'created_at' => $stamp]);
        }
        array_push($seen, ...array_column($response->json('data'), 'id'));
        $cursor = $response->json('next_before_id');
    } while ($cursor !== null && count($seen) < 7);

    expect($seen)->toBe($results->pluck('id')->reverse()->values()->all());
    $this->getJson('/api/v2/history?limit=201')->assertUnprocessable();
    $this->getJson('/api/v2/history?before_id=0')->assertUnprocessable();
});

test('every administrator network endpoint denies guests and ordinary tokens', function () {
    $routes = [
        ['GET', '/devices'], ['GET', '/rankings?days=7'], ['GET', '/sources'],
        ['POST', '/sources'], ['DELETE', '/sources/1'],
    ];
    foreach ($routes as [$method, $path]) {
        $this->json($method, '/api/network-monitor/v1'.$path)->assertUnauthorized();
    }
    $ordinary = User::factory()->create(['role' => UserRole::User]);
    $this->withToken($ordinary->createToken('Network monitor', ['network-monitor:admin'])->plainTextToken);
    foreach ($routes as [$method, $path]) {
        $this->json($method, '/api/network-monitor/v1'.$path)->assertForbidden();
    }
});

test('monitor migrations and their rollback preserve byte-for-byte result rows', function () {
    $migrations = [
        require database_path('migrations/2026_11_25_140000_add_result_metrics_indexes.php'),
        require database_path('migrations/2026_11_25_160000_create_network_monitor_tables.php'),
    ];
    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    Result::factory()->count(10)->create(['comments' => 'Existing history']);
    $history = fn () => DB::table('results')->orderBy('id')->get()->toJson();
    $before = $history();
    foreach ($migrations as $migration) {
        $migration->up();
    }
    expect($history())->toBe($before);
    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }
    expect($history())->toBe($before);
    foreach ($migrations as $migration) {
        $migration->up();
    }
});

test('retention removes abandoned discovery-only identities while preserving managed and observed devices', function () {
    $old = now()->subDays(100);
    $abandoned = DB::table('network_devices')->insertGetId(['first_seen_at' => $old, 'mac' => '02:00:00:00:00:01']);
    $named = DB::table('network_devices')->insertGetId(['first_seen_at' => $old, 'label' => 'Keep me']);
    $recent = DB::table('network_devices')->insertGetId(['first_seen_at' => $old, 'last_seen_at' => now()]);
    $observed = DB::table('network_devices')->insertGetId(['first_seen_at' => $old]);
    DB::table('network_presence')->insert(['device_id' => $observed, 'observed_at' => now(), 'reachable' => false, 'source' => 'lan']);

    app(Monitor::class)->prune();

    $this->assertDatabaseMissing('network_devices', ['id' => $abandoned]);
    expect(DB::table('network_devices')->orderBy('id')->pluck('id')->all())->toBe([$named, $recent, $observed]);
});
