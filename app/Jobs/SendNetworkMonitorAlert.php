<?php

namespace App\Jobs;

use App\Services\NetworkMonitor\Monitor;
use App\Settings\NotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SendNetworkMonitorAlert implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $event) {}

    public function handle(NotificationSettings $notifications): void
    {
        $settings = Monitor::settings();
        if (! $settings->enabled || ! $settings->discord_alerts || ! $notifications->discord_enabled) {
            return;
        }

        $content = $this->event === 'outage'
            ? 'Home Network Monitor detected an internet outage.'
            : 'Home Network Monitor discovered a new device.';

        foreach ($notifications->discord_webhooks ?? [] as $url) {
            if (! is_string($url) || ! preg_match('~^https://(?:discord\.com|discordapp\.com)/api/webhooks/[0-9]+/[a-zA-Z0-9_-]+$~D', $url)) {
                continue;
            }

            try {
                Http::timeout(5)->withoutRedirecting()->post($url, [
                    'content' => $content,
                    'allowed_mentions' => ['parse' => []],
                ]);
            } catch (ConnectionException) {
                // Avoid logging Discord credentials or network information.
            }
        }
    }
}
