<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosHttpResponse.php';

// The test suite declares its own PleosAccountIO (tests/FakePleosApi.php) before the library loads.
if (!trait_exists('PleosAccountIO')) {
    /**
     * The Pleos Account's only boundary to the outside world: HTTP (its clock is PleosClock).
     */
    trait PleosAccountIO
    {
        /**
         * @param array<string, string> $headers
         */
        protected function httpRequest(string $method, string $url, array $headers, ?string $body): PleosHttpResponse
        {
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $responseHeaders = [];
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_HTTPHEADER     => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int
                {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            return new PleosHttpResponse($result === false ? PleosHttpResponse::NO_RESPONSE : $status, $responseHeaders, is_string($result) ? $result : '');
        }
    }
}
