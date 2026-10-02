<?php

namespace App\Http\Controllers;

use App\Models\ManualPaymentRequest;
use Illuminate\Http\Request;

class AdminManualPaymentProofController extends Controller
{
    public function __invoke(
        Request $request,
        string $email,
        string $proof_token,
    ) {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $manualPaymentRequest = ManualPaymentRequest::with('user')
            ->where('proof_token', $proof_token)
            ->firstOrFail();

        // Verify the email matches the request's user
        abort_unless(
            $manualPaymentRequest->user && $manualPaymentRequest->user->email === $email,
            404,
        );

        $path = trim((string) $manualPaymentRequest->proof_path);

        if ($path === '') {
            return response('No proof file attached to this request.', 404)
                ->header('Content-Type', 'text/plain');
        }

        // Resolve absolute path — try the current local disk root first,
        // then the legacy root (before Laravel 11 changed it to app/private).
        $candidates = [
            storage_path('app/private/'.$path),
            storage_path('app/'.$path),
        ];

        $absolutePath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $absolutePath = $candidate;
                break;
            }
        }

        if ($absolutePath === null) {
            return response('Proof file not found on disk. DB path: '.$path, 404)
                ->header('Content-Type', 'text/plain');
        }

        return response()->file($absolutePath, [
            'Content-Disposition' => 'inline; filename="'.basename($absolutePath).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
