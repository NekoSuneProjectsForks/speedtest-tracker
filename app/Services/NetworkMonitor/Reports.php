<?php

namespace App\Services\NetworkMonitor;

use App\Jobs\SendNetworkMonitorAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class Reports
{
    public function source(Request $request, string $kind): stdClass
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 401);

        $source = DB::table('network_sources')->where('kind', $kind)->where('token_hash', hash('sha256', $token))->first();
        abort_if($source === null, 401);

        return $source;
    }

    public function collect(stdClass $source, array $data): void
    {
        $this->record($source, $data, function ($source, $settings, $now) use ($data) {
            $newDevices = false;
            foreach ($data['devices'] as $observation) {
                $mac = strtolower($observation['mac']);
                $device = DB::table('network_devices')->where('mac', $mac)->first();
                if ($device && ! $device->monitor) {
                    continue;
                }

                $reachable = (bool) $observation['reachable'];
                $addresses = array_filter([
                    'ipv4' => $observation['ipv4'] ?? null,
                    'ipv6' => $observation['ipv6'] ?? null,
                ]);

                if ($device === null) {
                    $id = DB::table('network_devices')->insertGetId($addresses + [
                        'mac' => $mac,
                        'first_seen_at' => $now,
                        'last_seen_at' => $reachable ? $now : null,
                    ]);
                    $newDevices = true;
                } else {
                    $id = $device->id;
                    if ($reachable) {
                        DB::table('network_devices')->where('id', $id)->update($addresses + ['last_seen_at' => $now]);
                    }
                }

                DB::table('network_presence')->insert([
                    'device_id' => $id,
                    'observed_at' => $now,
                    'reachable' => $reachable,
                    'source' => 'lan',
                    'latency_us' => $reachable ? ($observation['latency_us'] ?? null) : null,
                ]);
            }

            $previous = DB::table('network_connectivity')->orderByDesc('id')->first();
            $connectivity = array_intersect_key($data, array_flip(['internet', 'router', 'upnp', 'ipv4', 'ipv6', 'ping']));
            DB::table('network_connectivity')->insert($connectivity + ['observed_at' => $now]);

            $outage = DB::table('network_outages')->whereNull('ended_at')->whereNull('interrupted_at')->first();
            $continuous = $source->last_report_at && $previous
                && Carbon::parse($previous->observed_at)->gte($now->copy()->subSeconds($settings->interval_seconds * 3));
            if ($outage && ! $continuous) {
                DB::table('network_outages')->where('id', $outage->id)->update(['interrupted_at' => $previous?->observed_at ?? $now]);
            } elseif ($outage && $data['internet'] === 'up') {
                DB::table('network_outages')->where('id', $outage->id)->update(['ended_at' => $now]);
            } elseif ($outage === null && $continuous && $data['internet'] === 'down' && $previous->internet === 'down') {
                DB::table('network_outages')->insert(['started_at' => $previous->observed_at]);
                $this->alert($settings, 'outage');
            }

            if ($newDevices) {
                $this->alert($settings, 'devices');
            }

            return [];
        });
    }

    public function sample(stdClass $source, array $data): void
    {
        $this->record($source, $data, function ($source, $settings, $now) use ($data) {
            $device = DB::table('network_devices')->find($source->device_id);
            abort_unless($device?->monitor, 403);

            $elapsed = $source->last_report_at ? (int) Carbon::parse($source->last_report_at)->diffInSeconds($now) : 0;
            $rx = (int) $data['rx_bytes'] - (int) $source->rx_bytes;
            $tx = (int) $data['tx_bytes'] - (int) $source->tx_bytes;
            // Reject deltas above 100 Gbit/s per direction, including unusually large counter jumps.
            $maximum = $elapsed * 12_500_000_000;
            if ($source->session === $data['session'] && $elapsed >= 5 && $elapsed <= 900
                && $rx >= 0 && $tx >= 0 && max($rx, $tx) <= $maximum) {
                DB::table('network_traffic')->insert([
                    'device_id' => $device->id,
                    'observed_at' => $now,
                    'elapsed_seconds' => $elapsed,
                    'rx_bytes' => $rx,
                    'tx_bytes' => $tx,
                ]);
            }

            DB::table('network_devices')->where('id', $device->id)->update([
                'first_seen_at' => $device->first_seen_at ?? $now,
                'last_seen_at' => $now,
            ]);

            DB::table('network_presence')->insert([
                'device_id' => $device->id,
                'observed_at' => $now,
                'reachable' => true,
                'source' => 'agent',
            ]);
            if ($device->first_seen_at === null) {
                $this->alert($settings, 'devices');
            }

            return ['rx_bytes' => $data['rx_bytes'], 'tx_bytes' => $data['tx_bytes']];
        });
    }

    private function record(stdClass $source, array $data, callable $save): void
    {
        DB::transaction(function () use ($source, $data, $save) {
            $settings = DB::table('network_monitor_settings')->lockForUpdate()->find(1);
            abort_unless($settings->enabled, 403);

            $current = DB::table('network_sources')->where('token_hash', $source->token_hash)->lockForUpdate()->find($source->id);
            abort_if($current === null, 401);

            if ($current->session === $data['session'] && $current->sequence >= $data['sequence']) {
                return;
            }

            $now = now();
            $minimum = $current->kind === 'collector' ? max(5, $settings->interval_seconds - 5) : 5;
            abort_if($current->last_report_at && Carbon::parse($current->last_report_at)->addSeconds($minimum)->gt($now), 429);

            $fields = $save($current, $settings, $now);
            DB::table('network_sources')->where('id', $current->id)->update($fields + [
                'session' => $data['session'],
                'sequence' => $data['sequence'],
                'last_report_at' => $now,
            ]);
        }, 3);
    }

    private function alert(stdClass $settings, string $event): void
    {
        if ($settings->discord_alerts) {
            SendNetworkMonitorAlert::dispatch($event)->afterCommit();
        }
    }
}
