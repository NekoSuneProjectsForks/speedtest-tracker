<?php

namespace App\Services\NetworkMonitor;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class Monitor
{
    public static function settings(): stdClass
    {
        return DB::table('network_monitor_settings')->find(1);
    }

    public static function admin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function register(string $kind, string $label, ?int $deviceId): array
    {
        abort_unless(in_array($kind, ['collector', 'agent'], true), 422);

        return DB::transaction(function () use ($kind, $label, $deviceId) {
            $settings = DB::table('network_monitor_settings')->lockForUpdate()->find(1);
            abort_unless($settings->enabled, 403);

            if ($kind === 'collector') {
                abort_if(DB::table('network_sources')->where('kind', 'collector')->whereNotNull('token_hash')->exists(), 409);
            } else {
                $deviceId ??= DB::table('network_devices')->insertGetId(['label' => $label]);
                abort_if(DB::table('network_sources')->where('device_id', $deviceId)->whereNotNull('token_hash')->exists(), 409);
            }

            $token = bin2hex(random_bytes(32));
            $fields = [
                'kind' => $kind,
                'label' => $label,
                'token_hash' => hash('sha256', $token),
                'last_report_at' => null,
                'session' => null,
                'sequence' => null,
                'rx_bytes' => null,
                'tx_bytes' => null,
            ];

            $key = $deviceId === null ? ['kind' => 'collector'] : ['device_id' => $deviceId];
            DB::table('network_sources')->updateOrInsert($key, $fields);

            return ['id' => DB::table('network_sources')->where($key)->value('id'), 'token' => $token];
        }, 3);
    }

    public function configure(array $data): void
    {
        DB::transaction(function () use ($data) {
            $settings = DB::table('network_monitor_settings')->lockForUpdate()->find(1);
            DB::table('network_monitor_settings')->where('id', 1)->update($data);

            if ($settings->enabled && ! $data['enabled']) {
                DB::table('network_sources')->update(['session' => null, 'sequence' => null, 'last_report_at' => null]);
                DB::table('network_outages')->whereNull('ended_at')->whereNull('interrupted_at')->update(['interrupted_at' => now()]);
            }
        }, 3);
    }

    public function updateDevice(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data) {
            DB::table('network_monitor_settings')->lockForUpdate()->find(1);
            abort_unless(DB::table('network_devices')->where('id', $id)->exists(), 404);
            DB::table('network_devices')->where('id', $id)->update($data);
            if (! $data['monitor']) {
                DB::table('network_sources')->where('device_id', $id)->update(['session' => null, 'sequence' => null, 'last_report_at' => null]);
            }
        }, 3);
    }

    public function revoke(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('network_monitor_settings')->lockForUpdate()->find(1);
            $source = DB::table('network_sources')->find($id);
            abort_if($source === null, 404);
            DB::table('network_sources')->where('id', $id)->update([
                'token_hash' => null,
                'last_report_at' => null,
                'session' => null,
                'sequence' => null,
                'rx_bytes' => null,
                'tx_bytes' => null,
            ]);
            if ($source->kind === 'collector') {
                DB::table('network_outages')->whereNull('ended_at')->whereNull('interrupted_at')->update(['interrupted_at' => now()]);
            }
        }, 3);
    }

    public function rankings(CarbonInterface $since): Builder
    {
        return DB::table('network_traffic')
            ->join('network_devices', 'network_devices.id', '=', 'network_traffic.device_id')
            ->where('observed_at', '>=', $since)
            ->select('device_id', 'label')
            ->selectRaw('SUM(rx_bytes) AS download_bytes, SUM(tx_bytes) AS upload_bytes')
            ->groupBy('device_id', 'label')
            ->orderByRaw('SUM(rx_bytes) + SUM(tx_bytes) DESC')
            ->orderBy('device_id');
    }

    public function prune(): void
    {
        $cutoff = now()->subDays(self::settings()->retention_days);
        foreach (['network_presence', 'network_traffic', 'network_connectivity'] as $table) {
            DB::table($table)->where('observed_at', '<', $cutoff)->delete();
        }
        DB::table('network_outages')->where(fn ($query) => $query->where('ended_at', '<', $cutoff)->orWhere('interrupted_at', '<', $cutoff))->delete();
        DB::transaction(function () use ($cutoff) {
            DB::table('network_monitor_settings')->lockForUpdate()->find(1);

            // Keep administrator-managed devices and remove abandoned discovery-only identities.
            DB::table('network_devices')->whereNull('label')->where('monitor', true)
                ->where('first_seen_at', '<', $cutoff)
                ->where(fn (Builder $query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
                ->whereNotExists(fn (Builder $query) => $query->from('network_sources')->whereColumn('device_id', 'network_devices.id'))
                ->whereNotExists(fn (Builder $query) => $query->from('network_presence')->whereColumn('device_id', 'network_devices.id'))
                ->delete();
        }, 3);
    }
}
