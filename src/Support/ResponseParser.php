<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

class ResponseParser
{
    /**
     * Parse a query response with pagination info
     */
    public function parseQueryResponse(mixed $response): array
    {
        $data = $this->normalize($response);

        return [
            'records'        => $this->mapRecords($data['records'] ?? []),
            'totalSize'      => $data['totalSize'] ?? 0,
            'done'           => $data['done'] ?? true,
            'nextRecordsUrl' => $data['nextRecordsUrl'] ?? null,
        ];
    }

    /**
     * Parse a single record response
     */
    public function parseRecordResponse(mixed $response): array
    {
        $data = $this->normalize($response);

        return $this->stripAttributes($data);
    }

    /**
     * Parse a metadata/describe response
     */
    public function parseMetadataResponse(mixed $response): array
    {
        return $this->normalize($response);
    }

    /**
     * Parse a list response (versions, resources, etc.)
     */
    public function parseListResponse(mixed $response): array
    {
        return $this->normalize($response);
    }

    /**
     * Parse a create/upsert response
     */
    public function parseCreateResponse(mixed $response): array
    {
        $data = $this->normalize($response);

        return [
            'id'      => $data['id'] ?? null,
            'success' => $data['success'] ?? false,
            'errors'  => $data['errors'] ?? [],
        ];
    }

    /**
     * Normalize response to array format
     */
    protected function normalize(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Map multiple records (strips Salesforce metadata from each)
     */
    protected function mapRecords(array $records): array
    {
        return array_map(
            $this->stripAttributes(...),
            $records
        );
    }

    /**
     * Strip Salesforce metadata fields from a record
     */
    protected function stripAttributes(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if ($key === 'attributes') {
                continue;
            }

            // Handle nested relationship queries
            if (is_array($value) && isset($value['records'])) {
                $cleaned[$key] = array_map(
                    $this->stripAttributes(...),
                    $value['records']
                );

                continue;
            }

            // Handle nested objects
            if (is_array($value) && $value !== []) {
                $cleaned[$key] = $this->stripAttributes($value);

                continue;
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}
