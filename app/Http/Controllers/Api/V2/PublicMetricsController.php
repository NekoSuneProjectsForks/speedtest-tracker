<?php

namespace App\Http\Controllers\Api\V2;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicMetricsController extends Controller
{
    private function query()
    {
        return Result::query()->where('status', ResultStatus::Completed);
    }

    private function authorizeRead(Request $request): void
    {
        abort_if($request->user()->tokenCant('results:read'), 403);
    }

    private function number(mixed $value, float $scale = 1): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value * $scale, 3) : null;
    }

    private function metric(Result $result): array
    {
        return [
            'id' => (int) $result->id,
            'timestamp' => $result->created_at->toISOString(),
            'download_mbps' => $this->number($result->download, 0.000008),
            'upload_mbps' => $this->number($result->upload, 0.000008),
            'ping_ms' => $this->number($result->ping),
            'jitter_ms' => $this->number($result->ping_jitter),
            'packet_loss_pct' => $this->number($result->packet_loss),
        ];
    }

    public function latest(Request $request): JsonResponse
    {
        $this->authorizeRead($request);
        $result = $this->query()->select(['id', 'created_at', 'download', 'upload', 'ping', 'data'])
            ->orderByDesc('id')->first();

        return response()->json(['data' => $result ? $this->metric($result) : null]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeRead($request);
        $input = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:200',
            'before_id' => 'sometimes|integer|min:1',
            'days' => 'sometimes|integer|min:1|max:365',
        ]);
        $limit = (int) ($input['limit'] ?? 100);
        $rows = $this->query()->select(['id', 'created_at', 'download', 'upload', 'ping', 'data'])
            ->when(isset($input['days']), fn ($query) => $query->where('created_at', '>=', now()->subDays((int) $input['days'])))
            ->when(isset($input['before_id']), fn ($query) => $query->where('id', '<', $input['before_id']))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn ($row) => $this->metric($row))->values(),
            'next_before_id' => $more ? $rows->last()->id : null,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorizeRead($request);
        $input = $request->validate(['days' => 'sometimes|integer|min:1|max:365']);
        $days = (int) ($input['days'] ?? 7);
        $stats = Cache::remember("metrics.v2.stats.$days", 30, function () use ($days) {
            $row = $this->query()->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('count(*) as total, avg(download) as avg_down, min(download) as min_down, max(download) as max_down, avg(upload) as avg_up, min(upload) as min_up, max(upload) as max_up, avg(ping) as avg_ping, min(ping) as min_ping, max(ping) as max_ping')->first();
            $data = ['days' => $days, 'count' => (int) $row->total];
            foreach (['avg', 'min', 'max'] as $operation) {
                $data['download_mbps'][$operation] = $this->number($row->{$operation.'_down'}, 0.000008);
                $data['upload_mbps'][$operation] = $this->number($row->{$operation.'_up'}, 0.000008);
                $data['ping_ms'][$operation] = $this->number($row->{$operation.'_ping'});
            }
            return $data;
        });

        return response()->json(['data' => $stats]);
    }
}
