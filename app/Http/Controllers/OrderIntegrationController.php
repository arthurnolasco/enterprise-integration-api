<?php
namespace App\Http\Controllers;

use App\Http\Requests\OrderBatchRequest;
use App\Services\OrderIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderIntegrationController
{
    public function __construct(private readonly OrderIntegration $service) {}

    public function preview(OrderBatchRequest $request): JsonResponse
    {
        try {
            return response()->json($this->service->preview($request->validated('since')));
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Unable to retrieve orders.'], 502);
        }
    }

    public function dispatch(OrderBatchRequest $request): JsonResponse
    {
        try {
            return response()->json($this->service->dispatch($request->validated('since')), 202);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Unable to schedule transfer. Some jobs may already be queued.'], 500);
        }
    }

    public function status(string $orderId): JsonResponse
    {
        $transfer = DB::table('transfers')->where('order_id', $orderId)->first();
        return $transfer ? response()->json($transfer) : response()->json(['message' => 'No completed transfer recorded.'], 404);
    }
}
