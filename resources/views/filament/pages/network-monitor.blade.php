<x-filament-panels::page>
    <div class="flex flex-wrap gap-2" aria-label="Network Monitor sections">
        @foreach (['overview' => 'Network Overview', 'devices' => 'Connected Devices', 'bandwidth' => 'Device Bandwidth', 'router' => 'Router Integrations', 'history' => 'Network History', 'alerts' => 'Network Alerts', 'settings' => 'Monitoring Settings'] as $key => $title)
            <x-filament::button :color="$section === $key ? 'primary' : 'gray'" wire:click="showSection('{{ $key }}')" :aria-pressed="$section === $key ? 'true' : 'false'">
                {{ $title }}
            </x-filament::button>
        @endforeach
    </div>

    @unless ($settings->enabled)
        <p>Monitoring is disabled. Enable it in Monitoring Settings before registering a collector or agent.</p>
    @endunless

    @if (in_array($section, ['overview', 'router'], true))
        <x-filament::section heading="Connection status">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div><dt>Internet probes</dt><dd>{{ ucfirst($latest->internet ?? 'unknown') }}</dd></div>
                <div><dt>Local gateway reachability</dt><dd>{{ ucfirst($latest->router ?? 'unknown') }}</dd></div>
                <div><dt>UPnP WAN link</dt><dd>{{ ucfirst($latest->upnp ?? 'unknown') }}</dd></div>
            </dl>
            <p class="mt-4 text-sm">Latest current report {{ $latest->observed_at ?? 'unavailable' }}</p>
        </x-filament::section>
    @endif

    @if ($section === 'router')
        <x-filament::section heading="Detected capabilities">
            <ul class="space-y-2">
                <li>IPv4 LAN observation {{ ($latest->ipv4 ?? false) ? 'available' : 'unverified' }}</li>
                <li>IPv6 LAN observation {{ ($latest->ipv6 ?? false) ? 'available' : 'unverified' }}</li>
                <li>ICMP latency {{ ($latest->ping ?? false) ? 'available' : 'unverified' }}</li>
                <li>UPnP link status {{ isset($latest->upnp) && $latest->upnp !== 'unknown' ? 'available' : 'unverified' }}</li>
                <li>WAN usage unavailable</li>
                <li>Per-device bandwidth from router – Not supported by router</li>
            </ul>
        </x-filament::section>
    @endif

    @if ($section === 'devices')
        <x-filament::section heading="Connected devices">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm [&_td]:p-2 [&_th]:p-2">
                    <thead><tr><th>Device</th><th>MAC</th><th>Last known addresses</th><th>State</th><th>First seen</th><th>Last seen</th><th>Last measured latency</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($devices as $device)
                            <tr wire:key="device-{{ $device->id }}">
                                <td>{{ \App\Services\NetworkMonitor\ReportView::name($device) }}</td>
                                <td>{{ $device->mac ?? '—' }}</td>
                                <td>{{ $device->ipv4 ?? '—' }}<br>{{ $device->ipv6 ?? '—' }}</td>
                                <td>{{ \App\Services\NetworkMonitor\ReportView::status($device, $settings) }}</td>
                                <td>{{ $device->first_seen_at ?? '—' }}</td>
                                <td>{{ $device->last_seen_at ?? '—' }}</td>
                                <td>{{ $device->latency_us === null ? '—' : number_format($device->latency_us / 1000, 2).' ms' }}</td>
                                <td>
                                    <div class="flex gap-2">
                                        {{ ($this->editDeviceAction)(['id' => $device->id]) }}
                                        <x-filament::button color="gray" wire:click="showDeviceHistory({{ $device->id }})">History</x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8">No devices recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $devices->links() }}
        </x-filament::section>
    @endif

    @if ($section === 'bandwidth' || ($section === 'history' && $device !== null))
        <div class="flex gap-2" aria-label="Bandwidth time range">
            @foreach ([1 => 'Daily', 7 => 'Weekly', 30 => 'Monthly'] as $period => $label)
                <x-filament::button :color="$days === $period ? 'primary' : 'gray'" wire:click="setPeriod({{ $period }})" :aria-pressed="$days === $period ? 'true' : 'false'">{{ $label }}</x-filament::button>
            @endforeach
        </div>
    @endif

    @if ($section === 'bandwidth')
        <x-filament::section heading="Participating devices">
            <p class="text-sm">Bytes observed by agents in the past {{ $days }} days. Interface totals include both LAN and internet traffic. Gaps and expired samples are excluded.</p>
            <p class="text-sm">For devices without an agent: Not supported by router</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm [&_td]:p-2 [&_th]:p-2">
                    <thead><tr><th>Device</th><th>Download bytes</th><th>Upload bytes</th><th>Latest download / upload</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($rankings as $row)
                            <tr wire:key="ranking-{{ $row->device_id }}">
                                <td>{{ \App\Services\NetworkMonitor\ReportView::name($row) }}</td>
                                <td>{{ number_format($row->download_bytes) }}</td>
                                <td>{{ number_format($row->upload_bytes) }}</td>
                                <td>
                                    @if ($rate = $rates->get($row->device_id))
                                        {{ number_format($rate->rx_bytes * 8 / $rate->elapsed_seconds / 1000000, 2) }} /
                                        {{ number_format($rate->tx_bytes * 8 / $rate->elapsed_seconds / 1000000, 2) }} Mbps
                                        <br><span class="text-xs">{{ $rate->observed_at }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><x-filament::button color="gray" wire:click="showDeviceHistory({{ $row->device_id }})">History</x-filament::button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No valid agent traffic samples for this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $rankings->links() }}
        </x-filament::section>
    @endif

    @if ($section === 'history')
        @if ($device)
            <h2 class="text-lg font-semibold">{{ \App\Services\NetworkMonitor\ReportView::name($device) }}</h2>
            <x-filament::section heading="Agent bandwidth history">
                <table class="w-full text-left text-sm [&_td]:p-2 [&_th]:p-2">
                    <thead><tr><th>Period (application time)</th><th>Download bytes</th><th>Upload bytes</th></tr></thead>
                    <tbody>
                        @forelse ($usage as $period => $bytes)
                            <tr><td>{{ $period }}</td><td>{{ number_format($bytes['download']) }}</td><td>{{ number_format($bytes['upload']) }}</td></tr>
                        @empty
                            <tr><td colspan="3">No valid agent traffic samples.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        @endif
        <x-filament::section heading="Reachability history">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm [&_td]:p-2 [&_th]:p-2">
                    <thead><tr><th>Observed</th><th>Device</th><th>Reachability</th><th>Source</th><th>Latency</th></tr></thead>
                    <tbody>
                        @forelse ($presence as $row)
                            <tr>
                                <td>{{ $row->observed_at }}</td><td>{{ \App\Services\NetworkMonitor\ReportView::name($row) }}</td>
                                <td>{{ $row->reachable ? 'Observed' : 'Unconfirmed' }}</td>
                                <td>{{ $row->source === 'agent' ? 'Agent' : 'LAN collector' }}</td>
                                <td>{{ $row->latency_us === null ? '—' : number_format($row->latency_us / 1000, 2).' ms' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No reachability observations recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $presence->links() }}
        </x-filament::section>
    @endif

    @if ($section === 'alerts')
        <x-filament::section heading="Internet outages">
            <p class="text-sm">An outage requires all internet targets to fail in two consecutive collector reports. Timing is estimated from observations.</p>
            <table class="w-full text-left text-sm [&_td]:p-2 [&_th]:p-2">
                <thead><tr><th>First failed report</th><th>Recovery</th></tr></thead>
                <tbody>
                    @forelse ($outages as $outage)
                        <tr><td>{{ $outage->started_at }}</td><td>{{ $outage->ended_at ?? ($outage->interrupted_at ? 'Observation stopped '.$outage->interrupted_at : 'Recovery unconfirmed') }}</td></tr>
                    @empty
                        <tr><td colspan="2">No outages recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $outages->links() }}
        </x-filament::section>
    @endif

    @if ($section === 'settings')
        <x-filament::section heading="Monitoring settings">
            <p>Collector interval {{ $settings->interval_seconds }} seconds. History retention {{ $settings->retention_days }} days.</p>
            <p>Active discovery {{ $settings->active_discovery ? 'enabled' : 'disabled' }}. Discord alerts {{ $settings->discord_alerts ? 'enabled' : 'disabled' }}.</p>
            <div class="mt-4">{{ $this->settingsAction }}</div>
        </x-filament::section>
        <x-filament::section heading="Registered collectors and agents">
            @forelse ($sources as $source)
                <div class="mb-3 flex flex-wrap items-center gap-3" wire:key="source-{{ $source->id }}">
                    <span>{{ $source->label }} ({{ $source->kind }})</span>
                    <span>{{ $source->last_report_at ?? 'No reports' }}</span>
                    {{ ($this->revokeSourceAction)(['id' => $source->id]) }}
                </div>
            @empty
                <p class="mb-3">No active credentials.</p>
            @endforelse
            {{ $sources->links() }}
            {{ $this->registerSourceAction }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
