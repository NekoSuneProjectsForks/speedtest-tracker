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

    public int $tries = 28;

    public function __construct(public string $event, public string $destination) {}

    public static function dispatchFor(string $event, NotificationSettings $notifications): void
    {
        foreach (array_keys(self::destinations($notifications)) as $destination) {
            self::dispatch($event, $destination)->afterCommit();
        }
    }

    public function handle(NotificationSettings $notifications): void
    {
        $settings = Monitor::settings();
        $url = self::destinations($notifications)[$this->destination] ?? null;
        if (! $settings->enabled || ! $settings->discord_alerts || $url === null) {
            return;
        }

        $content = $this->event === 'outage'
            ? 'Home Network Monitor detected an internet outage.'
            : 'Home Network Monitor discovered a new device.';

        try {
            $response = Http::timeout(5)->withoutRedirecting()->post($url, [
                'content' => $content,
                'allowed_mentions' => ['parse' => []],
            ]);
            $retry = $response->serverError() || $response->status() === 429;
        } catch (ConnectionException) {
            // Retry without putting the webhook URL or request into a failed job log.
            $retry = true;
        }

        if ($retry && $this->attempts() < $this->tries) {
            $this->release(min(3600, 60 * (2 ** min(6, $this->attempts() - 1))));
        }
    }

    private static function destinations(NotificationSettings $notifications): array
    {
        if (! $notifications->discord_enabled) {
            return [];
        }

        $destinations = [];
        foreach ($notifications->discord_webhooks ?? [] as $url) {
            if (is_string($url) && preg_match('~^https://(?:discord\.com|discordapp\.com)/api/webhooks/[0-9]+/[a-zA-Z0-9_-]+$~D', $url)) {
                $destinations[hash('sha256', $url)] = $url;
            }
        }

        return $destinations;
    }
}
