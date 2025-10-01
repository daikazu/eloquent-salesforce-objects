<?php

use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Clear cache
    Cache::flush();

    // Set webhook configuration
    config([
        'eloquent-salesforce-objects.query_cache.enabled'                    => true,
        'eloquent-salesforce-objects.query_cache.webhook_invalidation'       => true,
        'eloquent-salesforce-objects.query_cache.webhook_secret'             => 'test-secret-key',
        'eloquent-salesforce-objects.query_cache.webhook_require_validation' => true,
    ]);
});

afterEach(function () {
    Cache::flush();
});

describe('webhook health check', function () {
    it('returns health status when webhook is enabled', function () {
        $response = $this->getJson('/api/salesforce/webhooks/health');

        $response->assertStatus(200)
            ->assertJson([
                'status'                       => 'ok',
                'webhook_invalidation_enabled' => true,
                'webhook_secret_configured'    => true,
            ])
            ->assertJsonStructure(['timestamp']);
    });

    it('shows secret not configured when missing', function () {
        config(['eloquent-salesforce-objects.query_cache.webhook_secret' => null]);

        $response = $this->getJson('/api/salesforce/webhooks/health');

        $response->assertStatus(200)
            ->assertJson([
                'webhook_secret_configured' => false,
            ]);
    });

    it('shows disabled when webhook invalidation is off', function () {
        config(['eloquent-salesforce-objects.query_cache.webhook_invalidation' => false]);

        $response = $this->getJson('/api/salesforce/webhooks/health');

        $response->assertStatus(200)
            ->assertJson([
                'webhook_invalidation_enabled' => false,
            ]);
    });
});

describe('webhook authentication', function () {
    it('rejects webhook without authentication', function () {
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized webhook request',
            ]);
    });

    it('accepts webhook with correct secret header', function () {
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    });

    it('accepts webhook with correct HMAC signature', function () {
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $payloadJson = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $payloadJson, 'test-secret-key');

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Signature' => $signature,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    });

    it('rejects webhook with incorrect secret', function () {
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
    });

    it('allows webhook when validation is disabled', function () {
        config(['eloquent-salesforce-objects.query_cache.webhook_require_validation' => false]);

        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    });
});

describe('CDC webhook processing', function () {
    it('processes valid CDC event and invalidates cache', function () {
        // Use object-level invalidation for this test
        config(['eloquent-salesforce-objects.query_cache.invalidation_strategy' => 'object']);

        $queryCache = app(QueryCache::class);

        // Pre-populate cache for Account
        Cache::tags(['sf_object_Account'])->put('test_key', 'test_value', 3600);

        expect(Cache::tags(['sf_object_Account'])->has('test_key'))->toBeTrue();

        $payload = [
            'schema'  => 'some-schema',
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName'      => 'Account',
                    'recordIds'       => ['001xx000003DGb2AAG'],
                    'changeType'      => 'UPDATE',
                    'changeOrigin'    => 'com.salesforce.api.soap',
                    'transactionKey'  => 'abc123',
                    'sequenceNumber'  => 1,
                    'commitTimestamp' => 1234567890,
                ],
                'Id'   => '001xx000003DGb2AAG',
                'Name' => 'Updated Account',
            ],
            'event' => [
                'replayId' => 123456,
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'          => true,
                'message'          => 'Cache invalidated successfully',
                'entity'           => 'Account',
                'records_affected' => 1,
            ]);

        // Verify cache was invalidated
        expect(Cache::tags(['sf_object_Account'])->has('test_key'))->toBeFalse();
    });

    it('handles multiple record IDs in CDC event', function () {
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Contact',
                    'recordIds'  => ['003xx000001', '003xx000002', '003xx000003'],
                    'changeType' => 'CREATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'          => true,
                'entity'           => 'Contact',
                'records_affected' => 3,
            ]);
    });

    it('handles different change types', function () {
        $changeTypes = ['CREATE', 'UPDATE', 'DELETE', 'UNDELETE'];

        foreach ($changeTypes as $changeType) {
            $payload = [
                'payload' => [
                    'ChangeEventHeader' => [
                        'entityName' => 'Opportunity',
                        'recordIds'  => ['006xx000001'],
                        'changeType' => $changeType,
                    ],
                ],
            ];

            $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
                'X-Salesforce-Webhook-Secret' => 'test-secret-key',
            ]);

            $response->assertStatus(200)
                ->assertJson(['success' => true]);
        }
    });

    it('rejects webhook when webhook invalidation is disabled', function () {
        config(['eloquent-salesforce-objects.query_cache.webhook_invalidation' => false]);

        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Webhook invalidation is not enabled',
            ]);
    });
});

describe('CDC webhook validation', function () {
    it('rejects webhook without ChangeEventHeader', function () {
        $payload = [
            'payload' => [
                'Id'   => '001xx000001',
                'Name' => 'Test',
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid CDC payload: missing ChangeEventHeader',
            ]);
    });

    it('rejects webhook without entityName', function () {
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid CDC payload: missing entityName',
            ]);
    });

    it('logs warnings for invalid payloads', function () {
        Log::shouldReceive('warning')
            ->once()
            ->with('Salesforce CDC webhook received without ChangeEventHeader', Mockery::any());

        $payload = [
            'payload' => [
                'Id' => '001xx000001',
            ],
        ];

        $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);
    });

    it('logs successful webhook processing', function () {
        Log::shouldReceive('info')
            ->once()
            ->with('Salesforce CDC webhook processed - cache invalidated', Mockery::any());

        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);
    });
});

describe('webhook error handling', function () {
    it('handles exceptions gracefully', function () {
        // Mock QueryCache to throw exception
        $this->mock(QueryCache::class, function ($mock) {
            $mock->shouldReceive('flushObject')
                ->andThrow(new \Exception('Cache error'));
        });

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to process Salesforce CDC webhook', Mockery::any());

        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'],
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret-key',
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Failed to process webhook',
            ]);
    });
});
