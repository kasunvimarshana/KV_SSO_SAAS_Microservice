<?php

namespace App\Http\Controllers;

use App\Models\SagaLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class SagaController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $sagas = SagaLog::where('tenant_id', $tenantId)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->order_id, fn($q, $o) => $q->where('order_id', $o))
            ->orderBy('started_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($sagas);
    }

    public function show(Request $request, string $sagaId): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $saga = SagaLog::where('tenant_id', $tenantId)
            ->where('saga_id', $sagaId)
            ->firstOrFail();

        return response()->json([
            'saga'  => $saga,
            'steps' => $saga->steps ?? [],
        ]);
    }

    public function retry(Request $request, string $sagaId): JsonResponse
    {
        $tenantId = $request->input('tenant_id');

        $saga = SagaLog::where('tenant_id', $tenantId)
            ->where('saga_id', $sagaId)
            ->firstOrFail();

        if (!in_array($saga->status, ['FAILED', 'COMPENSATED'])) {
            return response()->json(['error' => 'Only FAILED or COMPENSATED sagas can be retried'], 409);
        }

        // Mark for retry (in a real system, would re-trigger the saga workflow)
        $saga->update(['status' => 'PENDING_RETRY', 'retry_count' => ($saga->retry_count ?? 0) + 1]);

        return response()->json([
            'message' => 'Saga marked for retry',
            'saga'    => $saga->fresh(),
        ]);
    }
}
