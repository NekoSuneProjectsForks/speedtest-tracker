<?php

namespace App\Services\NetworkMonitor;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class ReportView
{
    public static function name(stdClass $device): string
    {
        return $device->label ?: ($device->mac ?? 'Device #'.($device->device_id ?? $device->id));
    }

    public static function status(stdClass $device, stdClass $settings): string
    {
        if (! $device->monitor || ! $settings->enabled) {
            return 'Paused';
        }

        if ($device->last_seen_at === null || Carbon::parse($device->last_seen_at)->lt(now()->subSeconds(max(180, $settings->interval_seconds * 3)))) {
            return 'Unknown';
        }

        return 'Recently seen';
    }

    public function data(string $section, int $days, ?int $deviceId): array
    {
        $settings = Monitor::settings();
        $data = compact('settings');
        if (in_array($section, ['overview', 'router'], true)) {
            $latest = DB::table('network_connectivity')->orderByDesc('id')->first();
            $lastReport = DB::table('network_sources')->where('kind', 'collector')->whereNotNull('token_hash')->value('last_report_at');
            $data['latest'] = $settings->enabled && $latest && $lastReport === $latest->observed_at
                && Carbon::parse($lastReport)->gte(now()->subSeconds($settings->interval_seconds * 3)) ? $latest : null;
        }

        if ($section === 'devices') {
            $data['devices'] = DB::table('network_devices')
                ->select('network_devices.*')
                ->addSelect(['latency_us' => DB::table('network_presence')->select('latency_us')
                    ->whereColumn('device_id', 'network_devices.id')->whereNotNull('latency_us')->orderByDesc('observed_at')->limit(1)])
                ->orderBy('network_devices.id')->simplePaginate(25);
        }

        if ($section === 'bandwidth') {
            $data['rankings'] = app(Monitor::class)->rankings(now()->subDays(in_array($days, [1, 7, 30], true) ? $days : 7))->simplePaginate(25);
            $data['rates'] = $settings->enabled
                ? DB::table('network_traffic as traffic')
                    ->join('network_sources as source', 'traffic.device_id', '=', 'source.device_id')
                    ->whereIn('traffic.device_id', $data['rankings']->pluck('device_id'))
                    ->whereNotNull('source.token_hash')
                    ->whereColumn('traffic.observed_at', 'source.last_report_at')
                    ->where('traffic.observed_at', '>=', now()->subMinutes(5))
                    ->select('traffic.device_id', 'traffic.rx_bytes', 'traffic.tx_bytes', 'traffic.elapsed_seconds', 'traffic.observed_at')
                    ->get()->keyBy('device_id')
                : collect();
        }

        if ($section === 'history') {
            $data['device'] = $deviceId ? DB::table('network_devices')->find($deviceId) : null;
            $data['presence'] = DB::table('network_presence')
                ->join('network_devices', 'network_devices.id', '=', 'network_presence.device_id')
                ->select('network_presence.*', 'network_devices.label', 'network_devices.mac')
                ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId))
                ->orderByDesc('observed_at')->orderByDesc('network_presence.id')->simplePaginate(25);
            $data['usage'] = $deviceId ? $this->usage($deviceId, $days) : [];
        }

        if ($section === 'alerts') {
            $data['outages'] = DB::table('network_outages')->orderByDesc('started_at')->orderByDesc('id')->simplePaginate(25);
        }

        if ($section === 'settings') {
            $data['sources'] = DB::table('network_sources')->select('id', 'label', 'kind', 'last_report_at')
                ->whereNotNull('token_hash')->orderBy('id')->simplePaginate(25);
        }

        return $data;
    }

    private function usage(int $deviceId, int $days): array
    {
        $daily = DB::table('network_traffic')->where('device_id', $deviceId)
            ->selectRaw('DATE(observed_at) AS recorded_day, SUM(rx_bytes) AS download_bytes, SUM(tx_bytes) AS upload_bytes')
            ->groupByRaw('DATE(observed_at)')->orderByDesc('recorded_day')->get();
        $periods = [];
        foreach ($daily as $row) {
            $date = Carbon::parse($row->recorded_day);
            $period = match ($days) {
                7 => $date->startOfWeek()->toDateString(),
                30 => $date->format('Y-m'),
                default => $row->recorded_day,
            };
            $periods[$period] ??= ['download' => 0, 'upload' => 0];
            $periods[$period]['download'] += $row->download_bytes;
            $periods[$period]['upload'] += $row->upload_bytes;
        }

        return array_slice($periods, 0, 30, true);
    }
}
