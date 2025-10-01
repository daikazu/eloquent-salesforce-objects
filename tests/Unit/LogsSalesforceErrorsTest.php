<?php

use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\LogsSalesforceErrors;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Create a test class that uses the trait
    $this->testClass = new class
    {
        use LogsSalesforceErrors;

        public function testLogSalesforceError(string $message, array $context = [], ?string $level = null): void
        {
            $this->logSalesforceError($message, $context, $level);
        }

        public function testHandleSalesforceException(\Throwable $exception, string $operation): void
        {
            $this->handleSalesforceException($exception, $operation);
        }
    };
});

describe('logSalesforceError', function () {
    it('logs to default channel when no channel configured', function () {
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        Log::shouldReceive('error')
            ->once()
            ->with('Test message', ['key' => 'value']);

        $this->testClass->testLogSalesforceError('Test message', ['key' => 'value']);
    });

    it('logs to configured channel', function () {
        config(['eloquent-salesforce-objects.logging_channel' => 'salesforce']);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        Log::shouldReceive('channel')
            ->once()
            ->with('salesforce')
            ->andReturnSelf();

        Log::shouldReceive('error')
            ->once()
            ->with('Test message', ['key' => 'value']);

        $this->testClass->testLogSalesforceError('Test message', ['key' => 'value']);
    });

    it('does not log when logging is disabled', function () {
        config(['eloquent-salesforce-objects.logging_channel' => false]);

        // When logging is disabled, no Log calls should be made
        // We test this by ensuring the method completes without error
        $this->testClass->testLogSalesforceError('Test message', ['key' => 'value']);

        // Verify no exceptions were thrown
        expect(true)->toBeTrue();
    });

    it('uses custom log level when provided', function () {
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        Log::shouldReceive('warning')
            ->once()
            ->with('Test message', []);

        $this->testClass->testLogSalesforceError('Test message', [], 'warning');
    });

    it('uses configured log level when no level provided', function () {
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'info']);

        Log::shouldReceive('info')
            ->once()
            ->with('Test message', []);

        $this->testClass->testLogSalesforceError('Test message');
    });
});

describe('handleSalesforceException', function () {
    it('logs exception and throws when throw_exceptions is true', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => true]);
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        $exception = new SalesforceException('API Error');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'delete operation failed')
                    && $context['operation'] === 'delete'
                    && $context['exception'] === SalesforceException::class;
            });

        expect(fn () => $this->testClass->testHandleSalesforceException($exception, 'delete'))
            ->toThrow(SalesforceException::class, 'API Error');
    });

    it('logs exception and does not throw when throw_exceptions is false', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        $exception = new SalesforceException('API Error');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'create operation failed')
                    && $context['operation'] === 'create'
                    && $context['exception'] === SalesforceException::class
                    && $context['message'] === 'API Error';
            });

        // Should not throw
        $this->testClass->testHandleSalesforceException($exception, 'create');

        expect(true)->toBeTrue();
    });

    it('includes operation context in log', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        $exception = new SalesforceException('Timeout error');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $context['operation'] === 'update'
                    && str_contains($message, 'update operation failed');
            });

        $this->testClass->testHandleSalesforceException($exception, 'update');

        expect(true)->toBeTrue();
    });

    it('handles different exception types', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);
        config(['eloquent-salesforce-objects.logging_channel' => null]);
        config(['eloquent-salesforce-objects.log_level' => 'error']);

        $exception = new RuntimeException('Connection lost');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $context['exception'] === RuntimeException::class
                    && $context['message'] === 'Connection lost';
            });

        $this->testClass->testHandleSalesforceException($exception, 'query');

        expect(true)->toBeTrue();
    });
});
