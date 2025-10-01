<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Http\Controllers;

use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming webhooks from Salesforce Change Data Capture (CDC)
 *
 * This controller receives CDC events from Salesforce and invalidates
 * the appropriate cache entries based on the changed records.
 */
class SalesforceWebhookController extends Controller
{
    public function __construct(
        protected QueryCache $queryCache
    ) {}

    /**
     * Handle incoming CDC webhook from Salesforce
     *
     * Expected payload structure:
     * {
     *   "schema": "...",
     *   "payload": {
     *     "ChangeEventHeader": {
     *       "entityName": "Account",
     *       "recordIds": ["001xx000003DGb2AAG"],
     *       "changeType": "UPDATE",
     *       "changeOrigin": "...",
     *       "transactionKey": "...",
     *       "sequenceNumber": 1,
     *       "commitTimestamp": 1234567890,
     *       "commitNumber": 123,
     *       "commitUser": "..."
     *     },
     *     "Id": "001xx000003DGb2AAG",
     *     "Name": "Acme Corp",
     *     ...
     *   },
     *   "event": {
     *     "replayId": 123456
     *   }
     * }
     */
    public function handle(Request $request): JsonResponse
    {
        if (! config('eloquent-salesforce-objects.query_cache.webhook_invalidation', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook invalidation is not enabled',
            ], 403);
        }

        try {
            $payload = $request->all();

            // Extract CDC event data
            $changeEventHeader = $payload['payload']['ChangeEventHeader'] ?? null;

            if (! $changeEventHeader) {
                Log::warning('Salesforce CDC webhook received without ChangeEventHeader', [
                    'payload' => $payload,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid CDC payload: missing ChangeEventHeader',
                ], 400);
            }

            $entityName = $changeEventHeader['entityName'] ?? null;
            $changeType = $changeEventHeader['changeType'] ?? null;
            $recordIds = $changeEventHeader['recordIds'] ?? [];

            if (! $entityName) {
                Log::warning('Salesforce CDC webhook received without entityName', [
                    'payload' => $payload,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid CDC payload: missing entityName',
                ], 400);
            }

            // Use appropriate invalidation strategy
            $strategy = $this->queryCache->getInvalidationStrategy();

            if ($strategy === 'record' && ! empty($recordIds)) {
                // Surgical invalidation - only invalidate queries containing these specific records
                $this->queryCache->invalidateByRecordIds($entityName, $recordIds);
                $invalidationType = 'record-level';
            } else {
                // Object-level invalidation - invalidate all queries for this object
                $this->queryCache->flushObject($entityName);
                $invalidationType = 'object-level';
            }

            $logContext = [
                'entity'            => $entityName,
                'change_type'       => $changeType,
                'record_ids'        => $recordIds,
                'record_count'      => count($recordIds),
                'invalidation_type' => $invalidationType,
                'strategy'          => $strategy,
            ];

            Log::info('Salesforce CDC webhook processed - cache invalidated', $logContext);

            return response()->json([
                'success'           => true,
                'message'           => 'Cache invalidated successfully',
                'entity'            => $entityName,
                'records_affected'  => count($recordIds),
                'invalidation_type' => $invalidationType,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to process Salesforce CDC webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Health check endpoint for webhook configuration
     */
    public function healthCheck(): JsonResponse
    {
        $enabled = config('eloquent-salesforce-objects.query_cache.webhook_invalidation', false);
        $hasSecret = ! empty(config('eloquent-salesforce-objects.query_cache.webhook_secret'));

        return response()->json([
            'status'                       => 'ok',
            'webhook_invalidation_enabled' => $enabled,
            'webhook_secret_configured'    => $hasSecret,
            'timestamp'                    => now()->toIso8601String(),
        ]);
    }
}
