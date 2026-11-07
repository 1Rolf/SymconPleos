<?php

declare(strict_types=1);

/**
 * One HTTP response the Pleos Account received from the Pleos API.
 */
final readonly class PleosHttpResponse
{
    /** The status of a request that got no response (timeout, DNS, TLS). */
    public const NO_RESPONSE = 0;

    /** The status of a request the Pleos API turned down for its rate limit; the Pleos Account then pauses. */
    public const TOO_MANY_REQUESTS = 429;

    /** @var array<string, string> the response headers, names in lower case */
    private array $headers;

    /**
     * @param int $status the HTTP status, NO_RESPONSE when the transfer failed
     * @param array<string, string> $headers the response headers, names in any case
     * @param string $body the raw body, '' when there is none
     */
    public function __construct(
        public int $status,
        array $headers,
        public string $body,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /** The value of a response header, '' when it is missing; the name is case-insensitive. */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** The decoded JSON body, null when it is empty or not JSON. */
    public function decodedBody(): mixed
    {
        return json_decode($this->body, true);
    }
}
