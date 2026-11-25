<?php

namespace App\Filament\Pages;

use App\Services\NetworkMonitor\Monitor;
use App\Services\NetworkMonitor\ReportView;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class NetworkMonitor extends Page
{
    use WithPagination;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $title = 'Home Network Monitor';

    protected static string|array $routeMiddleware = ['cache.headers:no_store'];

    protected string $view = 'filament.pages.network-monitor';

    public string $section = 'overview';

    public int $days = 7;

    public ?int $deviceId = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function hydrate(): void
    {
        Monitor::admin();
    }

    public function showSection(string $section): void
    {
        Monitor::admin();
        abort_unless(in_array($section, ['overview', 'devices', 'bandwidth', 'router', 'history', 'alerts', 'settings'], true), 404);
        $this->section = $section;
        $this->deviceId = null;
        $this->resetPage();
    }

    public function showDeviceHistory(int $id): void
    {
        $this->showSection('history');
        abort_unless(DB::table('network_devices')->where('id', $id)->exists(), 404);
        $this->deviceId = $id;
    }

    public function setPeriod(int $days): void
    {
        Monitor::admin();
        abort_unless(in_array($days, [1, 7, 30], true), 422);
        $this->days = $days;
        $this->resetPage();
    }

    public function settingsAction(): Action
    {
        return Action::make('settings')
            ->label('Configure monitoring')
            ->schema([
                Checkbox::make('enabled')->label('Enable Home Network Monitor'),
                Checkbox::make('active_discovery')->label('Enable active discovery of the configured local IPv4 subnet'),
                Checkbox::make('discord_alerts')->label('Send anonymous alerts to the configured Discord webhooks'),
                TextInput::make('interval_seconds')->label('Collector interval (seconds)')->integer()->minValue(30)->maxValue(900)->required(),
                TextInput::make('probes_per_round')->label('Maximum pings per round')->integer()->minValue(1)->maxValue(32)->required(),
                TextInput::make('retention_days')->label('History retention (days)')->integer()->minValue(1)->maxValue(365)->required(),
            ])
            ->fillForm(fn () => (array) Monitor::settings())
            ->action(function (array $data) {
                Monitor::admin();
                app(Monitor::class)->configure($data);
                Notification::make()->title('Monitoring settings saved')->success()->send();
            });
    }

    public function editDeviceAction(): Action
    {
        return Action::make('editDevice')
            ->label('Edit')
            ->schema([
                TextInput::make('label')->label('Friendly name')->maxLength(100),
                Checkbox::make('monitor')->label('Monitor this device'),
            ])
            ->fillForm(fn (array $arguments) => (array) DB::table('network_devices')->find((int) $arguments['id']))
            ->action(function (array $data, array $arguments) {
                Monitor::admin();
                app(Monitor::class)->updateDevice((int) $arguments['id'], $data);
            });
    }

    public function registerSourceAction(): Action
    {
        return Action::make('registerSource')
            ->label('Register collector or agent')
            ->visible(fn () => (bool) Monitor::settings()->enabled)
            ->schema([
                Select::make('kind')->options(['collector' => 'LAN collector', 'agent' => 'Windows or Linux bandwidth agent'])->live()->required(),
                TextInput::make('label')->maxLength(100)->required(),
                Select::make('device_id')->label('Existing device (optional)')
                    ->visible(fn ($get) => $get('kind') === 'agent')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('network_devices')
                        ->where('label', 'like', '%'.$search.'%')
                        ->orWhere('mac', 'like', '%'.$search.'%')
                        ->limit(25)->get()
                        ->mapWithKeys(fn ($device) => [$device->id => ReportView::name($device)])->all())
                    ->getOptionLabelUsing(fn ($value) => ($device = DB::table('network_devices')->find($value)) ? ReportView::name($device) : null)
                    ->exists('network_devices', 'id'),
            ])
            ->action(function (array $data) {
                Monitor::admin();
                ['token' => $token] = app(Monitor::class)->register($data['kind'], $data['label'], $data['kind'] === 'agent' ? ($data['device_id'] ?? null) : null);
                Notification::make()->title('Copy this token now')->body($token)->persistent()->send();
            });
    }

    public function revokeSourceAction(): Action
    {
        return Action::make('revokeSource')
            ->label('Revoke')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                Monitor::admin();
                app(Monitor::class)->revoke((int) $arguments['id']);
            });
    }

    protected function getViewData(): array
    {
        Monitor::admin();

        return app(ReportView::class)->data($this->section, $this->days, $this->deviceId);
    }
}
