<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminPayoutProofController extends Controller
{
    public function show(Request $request, string $payoutMethod): StreamedResponse
    {
        $row = DB::table('payout_methods')->where('id', $payoutMethod)->first();
        abort_unless($row && $row->proof_path, 404);
        $disk = $row->proof_disk ?: 'proofs';
        abort_unless(Storage::disk($disk)->exists($row->proof_path), 404);

        $this->audit($request, 'payout_proof.viewed', $payoutMethod, 'Reviewed private account proof', [
            'proof_status' => $row->proof_status,
        ]);

        return Storage::disk($disk)->response($row->proof_path, 'account-proof', [
            'Content-Type' => $row->proof_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="account-proof"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ]);
    }

    public function decide(Request $request, string $payoutMethod): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $payoutMethod, $data): JsonResponse {
            $row = DB::table('payout_methods')->where('id', $payoutMethod)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Payout method not found.', 404);
            }
            if ($row->proof_status !== 'pending' || ! $row->proof_path) {
                return ApiResponse::error('CONFLICT', 'This payout method has no account proof waiting for review.', 409);
            }

            $diskName = $row->proof_disk ?: 'proofs';
            $disk = Storage::disk($diskName);
            $proofPath = $row->proof_path;
            if ($data['decision'] === 'approved' && ! $disk->exists($proofPath)) {
                return ApiResponse::error('NOT_FOUND', 'Document not found.', 404);
            }

            $approved = $data['decision'] === 'approved';
            DB::table('payout_methods')->where('id', $payoutMethod)->update([
                'verified_at' => $approved ? now() : null,
                'proof_status' => $data['decision'],
                'proof_path' => null,
                'proof_disk' => null,
                'proof_mime' => null,
                'proof_reviewed_at' => now(),
                'proof_reviewed_by' => $request->user('admin')->id,
                'updated_at' => now(),
            ]);
            $this->audit($request, 'payout_proof.'.$data['decision'], $payoutMethod, $data['reason'], [
                'decision' => $data['decision'],
                'purged' => true,
            ]);
            if ($disk->exists($proofPath)) {
                $disk->delete($proofPath);
            }

            return ApiResponse::success([
                'id' => $payoutMethod,
                'proof_status' => $data['decision'],
                'verified' => $approved,
                'purged' => true,
            ]);
        }, 3);
    }

    private function audit(Request $request, string $action, string $id, string $reason, array $after): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'admin_id' => $request->user('admin')->id,
            'action' => $action,
            'subject_type' => 'App\\Models\\PayoutMethod',
            'subject_id' => $id,
            'reason' => $reason,
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
