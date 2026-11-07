<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosApi.php';

/**
 * Sends API requests through the Pleos Account this instance is connected to (see PleosAccount::ForwardData).
 */
trait PleosAccountClient
{
    /**
     * @param array<string, string> $query
     * @return array{Status: int, Body: mixed} the HTTP status (0 when the API could not be asked) and the decoded body
     */
    private function apiRequest(string $method, string $path, array $query = []): array
    {
        $result = json_decode($this->SendDataToParent(json_encode(['DataID' => PleosApi::DATA_ID, 'Method' => $method, 'Path' => $path, 'Query' => $query])), true);
        return ['Status' => (int) ($result['Status'] ?? 0), 'Body' => $result['Body'] ?? null];
    }
}
