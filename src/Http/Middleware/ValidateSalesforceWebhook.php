<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates incoming webhooks from Salesforce
 *
 * This middleware ensures that webhook requests are genuinely from Salesforce
 * by validating the signature or secret token.
 */
class ValidateSalesforceWebhook
{
    /**
     * Handle an incoming request
     *
     * @param  Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If webhook validation is disabled, allow all requests
        $requireValidation = config('eloquent-salesforce-objects.query_cache.webhook_require_validation', true);

        if (! $requireValidation) {
            return $next($request);
        }

        // Get webhook secret from config
        $webhookSecret = config('eloquent-salesforce-objects.query_cache.webhook_secret');

        if (empty($webhookSecret)) {
            Log::warning('Salesforce webhook secret not configured but validation is required');

            return response()->json([
                'success' => false,
                'message' => 'Webhook authentication not configured',
            ], 500);
        }

        // Validate using custom header (simple token approach)
        $providedSecret = $request->header('X-Salesforce-Webhook-Secret')
            ?? $request->header('X-Hook-Secret')
            ?? $request->input('secret');

        if ($providedSecret === $webhookSecret) {
            return $next($request);
        }

        // Validate using HMAC signature (more secure approach)
        $signature = $request->header('X-Salesforce-Signature')
            ?? $request->header('X-Hub-Signature');

        if ($signature && $this->validateSignature($request, $signature, $webhookSecret)) {
            return $next($request);
        }

        // Log failed authentication attempt
        Log::warning('Salesforce webhook authentication failed', [
            'ip'                   => $request->ip(),
            'user_agent'           => $request->userAgent(),
            'has_secret_header'    => $request->hasHeader('X-Salesforce-Webhook-Secret'),
            'has_signature_header' => $request->hasHeader('X-Salesforce-Signature'),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized webhook request',
        ], 401);
    }

    /**
     * Validate HMAC signature
     */
    protected function validateSignature(Request $request, string $signature, string $secret): bool
    {
        // Get raw request body
        $payload = $request->getContent();

        // Parse signature (format: sha256=hash or just hash)
        $providedHash = $signature;
        if (str_starts_with($signature, 'sha256=')) {
            $providedHash = substr($signature, 7);
        } elseif (str_starts_with($signature, 'sha1=')) {
            $providedHash = substr($signature, 5);
            $algorithm = 'sha1';
        }

        // Calculate expected signature
        $algorithm ??= 'sha256';
        $expectedHash = hash_hmac($algorithm, $payload, $secret);

        // Use timing-safe comparison
        return hash_equals($expectedHash, $providedHash);
    }
}
