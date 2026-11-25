<?php

namespace App\Http\Controllers\Api;

use App\Services\NetworkMonitor\Monitor;
use App\Services\NetworkMonitor\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class NetworkMonitorController
{
    public function devices()
    {
        $this->authorizeAdministrator();

        return response()->json(DB::table('network_devices')->orderBy('id')->paginate(50))
            ->header('Cache-Control', 'no-store');
    }

    public function rankings(Request $request, Monitor $monitor)
    {
        $this->authorizeAdministrator();
        $days = $request->validate(['days' => 'required|integer|in:1,7,30'])['days'];

        return response()->json($monitor->rankings(now()->subDays($days))->paginate(50))
            ->header('Cache-Control', 'no-store');
    }

    public function sources()
    {
        $this->authorizeAdministrator();

        return response()->json(DB::table('network_sources')->select('id', 'kind', 'label', 'device_id', 'last_report_at')
            ->whereNotNull('token_hash')->orderBy('id')->paginate(50))->header('Cache-Control', 'no-store');
    }

    public function register(Request $request, Monitor $monitor)
    {
        $this->authorizeAdministrator();
        $data = $request->validate([
            'kind' => 'required|in:collector,agent',
            'label' => 'required|string|max:100',
            'device_id' => 'nullable|prohibited_if:kind,collector|integer|exists:network_devices,id',
        ]);

        return response()->json($monitor->register($data['kind'], $data['label'], $data['device_id'] ?? null), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function revoke(int $id, Monitor $monitor)
    {
        $this->authorizeAdministrator();
        $monitor->revoke($id);

        return response()->noContent();
    }

    public function collectorSettings(Request $request, Reports $reports)
    {
        $reports->source($request, 'collector');
        $settings = Monitor::settings();

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'interval_seconds' => (int) $settings->interval_seconds,
            'active_discovery' => (bool) $settings->active_discovery,
            'probes_per_round' => (int) $settings->probes_per_round,
        ])->header('Cache-Control', 'no-store');
    }

    public function collect(Request $request, Reports $reports)
    {
        $source = $reports->source($request, 'collector');

        $data = $request->validate([
            'session' => 'required|uuid',
            'sequence' => 'required|integer|min:1|max:2147483647',
            'internet' => 'required|in:up,down,unknown',
            'router' => 'required|in:up,down,unknown',
            'upnp' => 'required|in:up,down,unknown',
            'ipv4' => 'required|boolean',
            'ipv6' => 'required|boolean',
            'ping' => 'required|boolean',
            'devices' => 'present|array|max:512',
            'devices.*.mac' => ['required', 'distinct:ignore_case', 'regex:/^[0-9a-f][02468ace](?::[0-9a-f]{2}){5}$/i', 'not_in:00:00:00:00:00:00'],
            'devices.*.ipv4' => 'nullable|ipv4',
            'devices.*.ipv6' => 'nullable|ipv6',
            'devices.*.reachable' => 'required|boolean',
            'devices.*.latency_us' => 'nullable|integer|min:0|max:3000000',
        ]);

        $reports->collect($source, $data);

        return response()->noContent();
    }

    public function sample(Request $request, Reports $reports)
    {
        $source = $reports->source($request, 'agent');

        $data = $request->validate([
            'session' => 'required|uuid',
            'sequence' => 'required|integer|min:1|max:2147483647',
            'rx_bytes' => 'required|integer|min:0|max:9007199254740991',
            'tx_bytes' => 'required|integer|min:0|max:9007199254740991',
        ]);

        $reports->sample($source, $data);

        return response()->noContent();
    }

    private function authorizeAdministrator(): void
    {
        Monitor::admin();
        $token = auth()->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && in_array('network-monitor:admin', $token->abilities, true), 403);
    }
}
