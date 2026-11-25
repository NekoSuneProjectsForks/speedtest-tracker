<?php

namespace App\Livewire;

use App\Enums\ResultStatus;
use App\Models\Result;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PlatformStats extends Component
{
    #[Computed]
    public function platformStats(): array
    {
        $counts = \Illuminate\Support\Facades\Cache::remember('dashboard.result-counts.v1', 30, fn () => Result::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->all());
        $totalResults = array_sum($counts);
        $completedResults = $counts[ResultStatus::Completed->value] ?? 0;
        $failedResults = $counts[ResultStatus::Failed->value] ?? 0;

        return [
            'total' => Number::format($totalResults),
            'completed' => Number::format($completedResults),
            'failed' => Number::format($failedResults),
        ];
    }

    public function render()
    {
        return view('livewire.platform-stats');
    }
}
