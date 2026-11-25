<?php

use App\Enums\UserRole;
use App\Filament\Pages\NetworkMonitor;
use App\Jobs\SendNetworkMonitorAlert;
use App\Models\User;
use App\Services\NetworkMonitor\Monitor;
use App\Settings\NotificationSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

function networkMonitorToken(string $kind, ?int $device = null): string
{
    return app(Monitor::class)->register($kind, 'Test '.ucfirst($kind), $device)['token'];
}

function networkMonitorRound(array $overrides = []): array
{
    return array_replace([
        'session' => (string) Str::uuid(),
        'sequence' => 1,
        'internet' => 'up',
        'router' => 'up',
        'upnp' => 'up',
        'ipv4' => true,
        'ipv6' => true,
        'ping' => true,
        'devices' => [],
    ], $overrides);
}

beforeEach(function () {
    config(['cache.default' => 'array', 'session.driver' => 'array']);
});

test('only administrators can open the Filament monitor or access its Livewire state', function () {
    $path = NetworkMonitor::getUrl();
    $this->get($path)->assertRedirect();

    $this->actingAs(User::factory()->create(['role' => UserRole::User]));
    $this->get($path)->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $this->withoutVite()->get($path)->assertOk()->assertSee('Monitoring is disabled');
    $page = Livewire::test(NetworkMonitor::class)->call('showSection', 'settings')
        ->assertSee('No active credentials');
    $page->call('showSection', 'devices')->assertSee('No devices recorded.');

    $this->actingAs(User::factory()->create(['role' => UserRole::User]));
    $page->call('showSection', 'devices')->assertForbidden();
});

test('Filament settings can enable monitoring, enroll credentials and edit device names', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    $page = Livewire::test(NetworkMonitor::class)->call('showSection', 'settings');
    $page->callAction('settings', data: [
        'enabled' => true,
        'active_discovery' => true,
        'discord_alerts' => false,
        'interval_seconds' => 75,
        'probes_per_round' => 12,
        'retention_days' => 45,
    ])->assertHasNoActionErrors();
    $this->assertDatabaseHas('network_monitor_settings', ['enabled' => true, 'interval_seconds' => 75]);

    $page->callAction('registerSource', data: ['kind' => 'agent', 'label' => 'Laptop'])
        ->assertHasNoActionErrors();
    $source = DB::table('network_sources')->where('label', 'Laptop')->first();
    expect($source?->token_hash)->not->toBeNull();

    $page->call('showSection', 'devices')->assertSee('Laptop')
        ->callAction('editDevice', data: ['label' => 'Work laptop', 'monitor' => false], arguments: ['id' => $source->device_id])
        ->assertHasNoActionErrors()->assertSee('Work laptop');
    $this->assertDatabaseHas('network_devices', ['id' => $source->device_id, 'monitor' => false]);

    $page->call('showSection', 'settings')->callAction('revokeSource', arguments: ['id' => $source->id]);
    $this->assertDatabaseHas('network_sources', ['id' => $source->id, 'token_hash' => null]);
});

test('administrator API requires a dedicated Sanctum ability and rejects source credentials', function () {
    $path = '/api/network-monitor/v1/devices';
    $this->getJson($path)->assertUnauthorized();
    foreach ([UserRole::User, UserRole::Admin] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $abilities = $role === UserRole::User ? ['network-monitor:admin'] : ['results:read'];
        $this->withToken($user->createToken('Scoped', $abilities)->plainTextToken)
            ->getJson($path)->assertForbidden();
        auth()->forgetGuards();
    }

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $token = $admin->createToken('Network Monitor', ['network-monitor:admin'])->plainTextToken;
    $this->withToken($token)->postJson('/api/network-monitor/v1/sources', ['label' => 'Test', 'kind' => 'collector'])->assertForbidden();
    DB::table('network_devices')->insert(['mac' => '02:11:22:33:44:55', 'ipv4' => '192.168.10.5', 'label' => 'Private device']);
    $this->getJson($path)->assertOk()->assertJsonPath('data.0.label', 'Private device')
        ->assertHeader('Cache-Control', 'no-store, private');

    DB::table('network_monitor_settings')->update(['enabled' => true]);
    $registered = $this->postJson('/api/network-monitor/v1/sources', ['label' => 'Test', 'kind' => 'collector'])->assertCreated();
    expect(DB::table('network_sources')->first()->token_hash)->toBe(hash('sha256', $registered->json('token')));
    $this->deleteJson('/api/network-monitor/v1/sources/'.DB::table('network_sources')->value('id'))->assertNoContent();
    auth()->forgetGuards();
    $this->withToken($registered->json('token'))->getJson('/api/network-monitor/v1/collector/settings')->assertUnauthorized();
    $this->getJson($path)->assertUnauthorized();
    foreach (['latest', 'history', 'stats'] as $endpoint) {
        $this->withToken('')->getJson('/api/v2/'.$endpoint)->assertOk()
            ->assertDontSee('Private device')->assertDontSee('02:11:22:33:44:55')->assertDontSee('192.168.10.5');
    }
});

test('collector records confirmed and unconfirmed presence without losing last seen, and applies scope and pause', function () {
    DB::table('network_monitor_settings')->update(['enabled' => true]);
    $collector = networkMonitorToken('collector');
    $agent = networkMonitorToken('agent');
    $path = '/api/network-monitor/v1/collector/reports';
    $observation = ['mac' => '02:11:22:33:44:55', 'ipv4' => '192.168.10.5', 'ipv6' => 'fe80::1234', 'reachable' => true, 'latency_us' => 3250];
    $report = networkMonitorRound(['devices' => [$observation]]);

    $this->withToken($agent)->postJson($path, $report)->assertUnauthorized();
    $this->withToken($collector)->postJson($path, $report)->assertNoContent();
    $this->postJson($path, $report)->assertNoContent();
    $device = DB::table('network_devices')->where('mac', $observation['mac'])->first();
    $this->assertDatabaseCount('network_presence', 1);
    $this->assertDatabaseHas('network_presence', ['device_id' => $device->id, 'latency_us' => 3250, 'reachable' => true]);

    $this->travel(60)->seconds();
    $report['sequence']++;
    $report['devices'][0]['reachable'] = false;
    $this->postJson($path, $report)->assertNoContent();
    expect(DB::table('network_devices')->find($device->id)->last_seen_at)->toBe($device->last_seen_at);
    $this->assertDatabaseHas('network_presence', ['device_id' => $device->id, 'latency_us' => null, 'reachable' => false]);

    app(Monitor::class)->updateDevice($device->id, ['label' => 'Friendly name', 'monitor' => false]);
    $this->travel(60)->seconds();
    $report['sequence']++;
    $this->postJson($path, $report)->assertNoContent();
    $this->assertDatabaseHas('network_devices', ['id' => $device->id, 'label' => 'Friendly name', 'monitor' => false]);
    $this->assertDatabaseCount('network_presence', 2);
});

test('outages need consecutive reports and close on recovery without UPnP traffic measurements', function () {
    Bus::fake([SendNetworkMonitorAlert::class]);
    DB::table('network_monitor_settings')->update(['enabled' => true, 'discord_alerts' => true]);
    $this->withToken(networkMonitorToken('collector'));
    $path = '/api/network-monitor/v1/collector/reports';
    $report = networkMonitorRound(['internet' => 'down', 'wan_bytes' => 4294967295]);
    $this->postJson($path, $report)->assertNoContent();
    $this->assertDatabaseCount('network_outages', 0);
    $this->postJson($path, $report)->assertNoContent();
    $this->assertDatabaseCount('network_outages', 0);
    $this->travel(60)->seconds();
    $report['sequence']++;
    $this->postJson($path, $report)->assertNoContent();
    $this->assertDatabaseCount('network_outages', 1);
    Bus::assertDispatched(SendNetworkMonitorAlert::class, fn ($job) => $job->event === 'outage');

    $this->travel(60)->seconds();
    $report['sequence']++;
    $report['internet'] = 'up';
    $this->postJson($path, $report)->assertNoContent();
    expect(DB::table('network_outages')->first()->ended_at)->not->toBeNull();
    $this->assertDatabaseCount('network_traffic', 0);
});

test('agent samples are bound to a device and tolerate retries, resets and gaps', function () {
    DB::table('network_monitor_settings')->update(['enabled' => true]);
    $this->withToken(networkMonitorToken('agent'));
    $path = '/api/network-monitor/v1/agent/samples';
    $sample = ['session' => (string) Str::uuid(), 'sequence' => 1, 'rx_bytes' => 500, 'tx_bytes' => 1000];
    $this->postJson($path, $sample)->assertNoContent();
    $this->assertDatabaseCount('network_traffic', 0);

    $sample = array_replace($sample, ['sequence' => 2, 'rx_bytes' => 700, 'tx_bytes' => 1300]);
    $this->postJson($path, $sample)->assertTooManyRequests();
    $this->travel(60)->seconds();
    $this->postJson($path, $sample)->assertNoContent();
    $this->postJson($path, $sample)->assertNoContent();

    $this->travel(60)->seconds();
    $this->postJson($path, array_replace($sample, ['sequence' => 3, 'rx_bytes' => 12]))->assertNoContent();
    $this->travel(16)->minutes();
    $this->postJson($path, array_replace($sample, ['sequence' => 4, 'rx_bytes' => 800]))->assertNoContent();

    $this->travel(60)->seconds();
    $this->postJson($path, array_replace($sample, ['session' => (string) Str::uuid(), 'sequence' => 1, 'rx_bytes' => 900]))->assertNoContent();
    $this->assertDatabaseCount('network_traffic', 1);
    $this->assertDatabaseHas('network_traffic', ['elapsed_seconds' => 60, 'rx_bytes' => 200, 'tx_bytes' => 300]);
    $ranking = app(Monitor::class)->rankings(now()->subDay())->first();
    expect((int) $ranking->download_bytes)->toBe(200)->and((int) $ranking->upload_bytes)->toBe(300);

    app(Monitor::class)->revoke(DB::table('network_sources')->value('id'));
    $this->postJson($path, $sample)->assertUnauthorized();
});

test('retention prunes history and Discord alerts have no device information', function () {
    DB::table('network_monitor_settings')->update(['enabled' => true, 'discord_alerts' => true, 'retention_days' => 7]);
    $device = DB::table('network_devices')->insertGetId(['label' => 'Private device']);
    foreach ([1, 10] as $days) {
        DB::table('network_traffic')->insert([
            'device_id' => $device,
            'observed_at' => now()->subDays($days),
            'elapsed_seconds' => 60,
            'rx_bytes' => 10,
            'tx_bytes' => 20,
        ]);
    }
    app(Monitor::class)->prune();
    $this->assertDatabaseCount('network_traffic', 1);
    $this->assertDatabaseCount('network_devices', 1);

    Http::fake();
    $settings = app(NotificationSettings::class);
    $settings->discord_enabled = true;
    $settings->discord_webhooks = ['https://discord.com/api/webhooks/123/test-token', 'https://example.test/api/webhooks/123/test-token'];
    (new SendNetworkMonitorAlert('devices'))->handle($settings);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['content'] === 'Home Network Monitor discovered a new device.');
});
