<?php

namespace App\Http\Controllers;

use App\Models\ManualPaymentRequest;
use App\Services\ManualPaymentService;
use Illuminate\Http\Request;

class AdminManualPaymentProofController extends Controller
{
    public function __invoke(
        Request $request,
        ManualPaymentRequest $manualPaymentRequest,
        ManualPaymentService $manualPaymentService
    ) {
        abort_unless((bool) $request->user()?->is_admin, 403);

        return $manualPaymentService->proofResponse($manualPaymentRequest);
    }
}
