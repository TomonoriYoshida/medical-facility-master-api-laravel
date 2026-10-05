<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublicHolidayIndexRequest;
use App\Models\PublicHoliday;
use Illuminate\Http\JsonResponse;

class PublicHolidayController extends Controller
{
    use ProvidesAttribution;

    /**
     * 祝日の一覧
     *
     * 内閣府「国民の祝日」の一覧から、期間内の祝日・休日（振替休日・国民の休日を含む）を日付順に返します。
     * 一覧には翌年末までが載り、翌年分は例年2月ごろに追加されます。年末年始やお盆は含みません。
     * 一覧APIの `open_at` は、祝日には診療時間の「祝」の時刻で判定します。
     */
    public function __invoke(PublicHolidayIndexRequest $request): JsonResponse
    {
        $today = now('Asia/Tokyo');
        $from = $request->validated('from') ?? $today->copy()->startOfYear()->toDateString();
        $to = $request->validated('to') ?? $today->copy()->addYear()->endOfYear()->toDateString();

        return response()->json([
            /** @var list<array{date: string, name: string}> */
            'data' => PublicHoliday::query()
                ->whereBetween('date', [$from, $to])
                ->orderBy('date')
                ->get()
                ->map(fn (PublicHoliday $holiday): array => [
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                ])
                ->values(),
            'meta' => ['attribution' => $this->attribution()],
        ]);
    }
}
