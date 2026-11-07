<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosApi.php';
include_once __DIR__ . '/PleosDataPointCatalog.php';

/**
 * What one request of a Data Group delivered: the HTTP status, the API error code and the data. Every API code and
 * status the Pleos Vehicle acts on is named here once.
 */
final readonly class PleosDataGroupResult
{
    /** The API's error code for "No Data": the Vehicle has not sent this Data Group yet. */
    private const NO_DATA = '4045';

    /** API error codes meaning the API Key no longer covers this Vehicle: unregistered vehicle, consent missing or withdrawn. */
    private const NOT_COVERED_CODES = ['4101', '5006'];

    /** The status of a request the Pleos Account could not send (no Access Token, rate limited) or got no answer to. */
    private const NOT_ASKED = 0;

    private const OK = 200;

    /** HTTP statuses of a refused request parameter, such as a Trip date range longer than the API allows. */
    private const REFUSED_PARAMETER = [400, 422];

    /**
     * @param string|null $code the API error code ('' for success), or null when the API did not answer
     * @param array<string, mixed>|null $data the response's `data`, null when it delivered none
     */
    private function __construct(
        public int $status,
        public ?string $code,
        public ?array $data,
    ) {
    }

    /** @param array{Status: int, Body: mixed} $response an Account request's response */
    public static function fromResponse(array $response): self
    {
        $data = $response['Status'] === self::OK ? ($response['Body']['data'] ?? null) : null;
        return new self($response['Status'], self::errorCode($response), is_array($data) ? $data : null);
    }

    /**
     * The same answer with other data, such as only the new Trips; null for none.
     *
     * @param array<string, mixed>|null $data
     */
    public function withData(?array $data): self
    {
        return new self($this->status, $this->code, $data);
    }

    /** Whether the Data Group delivered data. */
    public function delivered(): bool
    {
        return $this->data !== null;
    }

    /** Whether the API answered with HTTP 200, with or without data. */
    public function succeeded(): bool
    {
        return $this->status === self::OK;
    }

    /** Whether the API answered "No Data" (4045): the Vehicle has not sent this Data Group yet. */
    public function noDataYet(): bool
    {
        return $this->code === self::NO_DATA;
    }

    /** Whether the API Key no longer covers the Vehicle (4101, 5006). */
    public function notCovered(): bool
    {
        return in_array($this->code, self::NOT_COVERED_CODES, true);
    }

    /**
     * Whether the API gave no answer to act on: no response at all (status 0) or a server error (5xx). The status rule
     * and the Availability Check count it as an outage, not as an answer.
     */
    public function unreachable(): bool
    {
        return $this->code === null;
    }

    /**
     * Whether no HTTP response came back at all (status 0): the Account could not send the request (no Access Token,
     * rate limited) or the request got no response. Unlike unreachable(), a server error does not count. It stops a
     * poll, because the rest would fail alike and each request would fetch another Access Token.
     */
    public function notAsked(): bool
    {
        return $this->status === self::NOT_ASKED;
    }

    /** Whether the API refused the request's parameters (HTTP 400 or 422), not the Vehicle or the token. */
    public function rangeRefused(): bool
    {
        return in_array($this->status, self::REFUSED_PARAMETER, true) && !$this->notCovered();
    }

    /** The Report Time of the data; Trip Data Groups have none, so the start of their latest Trip stands in. */
    public function reportTime(): ?int
    {
        if ($this->data === null) {
            return null;
        }
        if (array_key_exists('timestamp', $this->data)) {
            return PleosDataPointCatalog::parseUtcTime($this->data['timestamp']);
        }
        $starts = array_keys(PleosDataPointCatalog::trips($this->data));
        return $starts === [] ? null : max($starts);
    }

    /**
     * @param array{Status: int, Body: mixed} $response
     * @return string|null the API error code ('' for success), or null when the API did not answer
     */
    private static function errorCode(array $response): ?string
    {
        if (PleosApi::isUnreachable($response['Status'])) {
            return null;
        }
        $code = is_array($response['Body']) ? ($response['Body']['error']['code'] ?? '') : '';
        return is_scalar($code) ? (string) $code : '';
    }
}
