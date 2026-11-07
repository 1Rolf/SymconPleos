<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/PleosAccountIO.php';
include_once __DIR__ . '/../libs/PleosApi.php';
include_once __DIR__ . '/../libs/PleosClock.php';
include_once __DIR__ . '/../libs/PleosHttpResponse.php';
include_once __DIR__ . '/../libs/PleosPrivacy.php';
include_once __DIR__ . '/../libs/PleosTokenOutcome.php';

class PleosAccount extends IPSModuleStrict
{
    use PleosAccountIO;
    use PleosClock;

    private const BASE_URL = 'https://api.pleos.ai/v1';

    private const STATUS_INVALID_API_KEY = 201;

    private const STATUS_ACCESS_DENIED = 202;

    private const STATUS_RATE_LIMITED = PleosApi::ACCOUNT_RATE_LIMITED;

    /** The shortest pause after a 429, whatever Retry-After says. */
    private const MIN_RATE_LIMIT_PAUSE = 300;

    /** The shortest time between two token requests after one failed; the connection test ignores it (#23). */
    private const TOKEN_RETRY_PAUSE = 60;

    /** Long enough for a token fetch plus a retried request, each with the 30 s HTTP timeout. */
    private const LOCK_TIMEOUT_MS = 100000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Brand', '');
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');

        $this->RegisterTimer('RateLimit', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'EndRateLimit\', 0);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // A changed API Key or Brand makes the current Access Token useless, and deserves a token request at once.
        $this->SetBuffer('AccessToken', '');
        $this->endTokenPause();
        if (!$this->isConfigured()) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $this->SetStatus($this->isRateLimited() ? self::STATUS_RATE_LIMITED : IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'EndRateLimit') {
            throw new InvalidArgumentException('Invalid ident ' . $Ident);
        }
        // Only reports the end of the pause; requests check the pause themselves.
        $this->isRateLimited();
    }

    /**
     * Sends one API request on behalf of a child instance.
     *
     * The child sends {"DataID", "Method", "Path", "Query"}; the reply is {"Status", "Body"}
     * with the HTTP status (0 when the API could not be asked) and the decoded response body.
     * Requests are serialized, and the Access Token is reused and replaced once on 401/403.
     */
    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $method = strtoupper((string) ($data['Method'] ?? 'GET'));
        $path = (string) ($data['Path'] ?? '');
        $query = is_array($data['Query'] ?? null) ? $data['Query'] : [];
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        if (!$this->isConfigured()) {
            return $this->notSent('Account is not configured');
        }
        if ($this->isRateLimited()) {
            return $this->notSent('Rate limited until ' . $this->rateLimitEnd());
        }
        if (!$this->lock()) {
            return $this->notSent('Account is busy');
        }
        try {
            $response = $this->authorizedRequest($method, $path);
        } finally {
            $this->unlock();
        }
        if ($response === null) {
            return $this->notSent('No valid Access Token');
        }
        // Keeps a float such as 23.0 a float on its way to the child.
        return json_encode(['Status' => $response->status, 'Body' => $response->decodedBody()], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Fetches a fresh Access Token and returns a message for the owner.
     */
    public function TestConnection(): string
    {
        if (!$this->isConfigured()) {
            return $this->Translate('Please select the Brand and enter Client ID and Client Secret, then apply the changes');
        }
        if ($this->isRateLimited()) {
            return $this->Translate('The Pleos API limits the number of requests, please try again later');
        }
        if (!$this->lock()) {
            return $this->Translate('The Pleos Account is busy, please try again');
        }
        try {
            $outcome = $this->fetchAccessToken();
        } finally {
            $this->unlock();
        }
        if ($outcome === PleosTokenOutcome::Valid) {
            $this->SetStatus(IS_ACTIVE);
        } elseif ($outcome === PleosTokenOutcome::InvalidApiKey) {
            $this->SetStatus(self::STATUS_INVALID_API_KEY);
        }
        // One Translate() per text, so the localization test sees each of them.
        return match ($outcome) {
            PleosTokenOutcome::Valid         => $this->Translate('Connection successful'),
            PleosTokenOutcome::InvalidApiKey => $this->Translate('Invalid Client ID, Client Secret or Brand, or expired API Key'),
            PleosTokenOutcome::RateLimited   => $this->Translate('The Pleos API limits the number of requests, please try again later'),
            PleosTokenOutcome::Refused       => $this->Translate('The Pleos API refused the Access Token request, please try again later'),
            PleosTokenOutcome::Unreachable   => $this->Translate('The Pleos API is currently not reachable, please try again later'),
        };
    }

    /**
     * Sends a data request with the current Access Token. A token rejected with 401/403 is replaced once
     * and the request retried; a rejection of a fresh token means access denied.
     *
     * @return PleosHttpResponse|null null when no Access Token could be obtained
     */
    private function authorizedRequest(string $method, string $path): ?PleosHttpResponse
    {
        $fresh = false;
        if ($this->GetBuffer('AccessToken') === '') {
            if (!$this->renewAccessToken()) {
                return null;
            }
            $fresh = true;
        }
        $response = $this->request($method, $path, ['Authorization' => 'Bearer ' . $this->GetBuffer('AccessToken')], null);
        if ($this->isTokenRejected($response->status) && !$fresh) {
            if (!$this->renewAccessToken()) {
                return null;
            }
            $response = $this->request($method, $path, ['Authorization' => 'Bearer ' . $this->GetBuffer('AccessToken')], null);
        }
        if ($this->isTokenRejected($response->status)) {
            if ($this->GetStatus() !== self::STATUS_ACCESS_DENIED) {
                $this->LogMessage($this->Translate('Access denied: the API Key may be used by another client or revoked'), KL_ERROR);
                $this->SetStatus(self::STATUS_ACCESS_DENIED);
            }
        } elseif (!PleosApi::isUnreachable($response->status) && $response->status !== PleosHttpResponse::TOO_MANY_REQUESTS && $this->GetStatus() !== IS_ACTIVE) {
            // The API accepted the Access Token.
            $this->SetStatus(IS_ACTIVE);
        }
        return $response;
    }

    /**
     * Fetches a new Access Token unless a token request failed less than TOKEN_RETRY_PAUSE ago, so a refusal or
     * outage costs one token request per pause instead of one per request.
     */
    private function renewAccessToken(): bool
    {
        $pausedUntil = (int) $this->GetBuffer('TokenPausedUntil');
        if ($this->currentTime() < $pausedUntil) {
            $this->SendDebug('Access Token', 'No token request until ' . date('Y-m-d H:i:s', $pausedUntil) . ', the last one failed', 0);
            return false;
        }
        $outcome = $this->fetchAccessToken();
        // A 429 has its own, longer pause.
        if ($outcome !== PleosTokenOutcome::Valid && $outcome !== PleosTokenOutcome::RateLimited) {
            $this->SetBuffer('TokenPausedUntil', (string) ($this->currentTime() + self::TOKEN_RETRY_PAUSE));
        }
        if ($outcome === PleosTokenOutcome::InvalidApiKey) {
            $this->SetStatus(self::STATUS_INVALID_API_KEY);
        }
        return $outcome === PleosTokenOutcome::Valid;
    }

    /** Lets the next token request go out at once and warn again about a refusal. */
    private function endTokenPause(): void
    {
        $this->SetBuffer('TokenPausedUntil', '');
        $this->SetBuffer('TokenRefused', '');
    }

    private function isTokenRejected(int $status): bool
    {
        return $status === 401 || $status === 403;
    }

    private function notSent(string $reason): string
    {
        $this->SendDebug('Request not sent', $reason, 0);
        return json_encode(['Status' => 0, 'Body' => null]);
    }

    /**
     * Serializes all API requests of this Account, so a token fetch never overlaps another request.
     */
    private function lock(): bool
    {
        return IPS_SemaphoreEnter('PleosAccount.' . $this->InstanceID, self::LOCK_TIMEOUT_MS);
    }

    private function unlock(): void
    {
        IPS_SemaphoreLeave('PleosAccount.' . $this->InstanceID);
    }

    private function isConfigured(): bool
    {
        return $this->ReadPropertyString('Brand') !== '' && $this->ReadPropertyString('ClientID') !== '' && $this->ReadPropertyString('ClientSecret') !== '';
    }

    /**
     * Requests a new Access Token and keeps it in the non-persistent buffer.
     *
     * Only an answer that blames the credentials means an invalid API Key: any HTTP 400 (live: 4002 for bad
     * credentials or Brand), 404 with 40404 "User or key not found", and a 200 without an Access Token.
     * Any other refusal, such as the 403 seen live on 2026-11-04 (#23), is taken as temporary.
     * The invalid API Key is logged once, when the status turns to it.
     */
    private function fetchAccessToken(): PleosTokenOutcome
    {
        $this->SetBuffer('AccessToken', '');
        $body = json_encode([
            'clientId'     => $this->ReadPropertyString('ClientID'),
            'clientSecret' => $this->ReadPropertyString('ClientSecret'),
        ]);
        $response = $this->request('POST', '/auth/personal-token', ['Content-Type' => 'application/json'], $body);
        $decoded = $response->decodedBody();
        $token = $decoded['data']['accessToken'] ?? null;
        if ($response->status === PleosHttpResponse::TOO_MANY_REQUESTS) {
            return PleosTokenOutcome::RateLimited;
        }
        if (PleosApi::isUnreachable($response->status)) {
            // request() has already warned about the outage.
            return PleosTokenOutcome::Unreachable;
        }
        if ($response->status === 200 && is_string($token) && $token !== '') {
            $this->SetBuffer('AccessToken', $token);
            $this->endTokenPause();
            return PleosTokenOutcome::Valid;
        }
        if ($response->status === 200 || $response->status === 400 || ($response->status === 404 && $this->errorCodeOf($decoded) === '40404')) {
            if ($this->GetStatus() !== self::STATUS_INVALID_API_KEY) {
                $this->LogMessage($this->redact(sprintf($this->Translate('Access Token request failed: %s'), $this->describeError($response->status, $decoded))), KL_ERROR);
            }
            return PleosTokenOutcome::InvalidApiKey;
        }
        // Warns once per incident: from the first refusal until a token request succeeds.
        if ($this->GetBuffer('TokenRefused') === '') {
            $this->SetBuffer('TokenRefused', (string) $this->currentTime());
            $this->LogMessage($this->redact(sprintf($this->Translate('The Pleos API refused the Access Token request (%s), retrying later'), $this->describeError($response->status, $decoded))), KL_WARNING);
        }
        return PleosTokenOutcome::Refused;
    }

    /**
     * Sends one request to the Pleos API with the Brand header and debugs it redacted.
     *
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers, ?string $body): PleosHttpResponse
    {
        $headers = ['Brand' => $this->ReadPropertyString('Brand')] + $headers;
        $this->SendDebug('Request', $this->redact($method . ' ' . $path . ' ' . json_encode(array_diff_key($headers, ['Authorization' => true])) . ($body === null ? '' : ' ' . $body)), 0);
        $response = $this->httpRequest($method, self::BASE_URL . $path, $headers, $body);
        $this->SendDebug('Response', $this->redact('HTTP ' . $response->status . ' ' . PleosPrivacy::maskJsonForDebug($response->body)), 0);
        if ($response->status === PleosHttpResponse::TOO_MANY_REQUESTS) {
            $this->startRateLimit($response->header('Retry-After'));
        }
        // Unreachable requests are retried at the next poll without a status change.
        if (PleosApi::isUnreachable($response->status)) {
            $this->reportOutage($method, $path, $response);
        } elseif ($this->GetBuffer('Outage') !== '') {
            $this->SetBuffer('Outage', '');
            $this->SendDebug('Outage', 'The Pleos API answers again', 0);
        }
        return $response;
    }

    /**
     * Warns once per incident: from the first failed request until the API answers again.
     */
    private function reportOutage(string $method, string $path, PleosHttpResponse $response): void
    {
        if ($this->GetBuffer('Outage') !== '') {
            return;
        }
        $this->SetBuffer('Outage', (string) $this->currentTime());
        $reason = $response->status === PleosHttpResponse::NO_RESPONSE ? $this->Translate('no response') : $this->describeError($response->status, $response->decodedBody());
        $this->LogMessage($this->redact(sprintf($this->Translate('The Pleos API is not reachable (%s), retrying at the next poll'), $method . ' ' . (string) strtok($path, '?') . ': ' . $reason)), KL_WARNING);
    }

    /**
     * Pauses all requests until max(Retry-After, 5 min). Retry-After may be seconds or an HTTP date.
     */
    private function startRateLimit(string $retryAfter): void
    {
        $now = $this->currentTime();
        $wait = 0;
        if (ctype_digit($retryAfter)) {
            $wait = (int) $retryAfter;
        } elseif ($retryAfter !== '' && ($date = strtotime($retryAfter)) !== false) {
            $wait = $date - $now;
        }
        $until = $now + max($wait, self::MIN_RATE_LIMIT_PAUSE);
        $this->SetBuffer('RateLimitedUntil', (string) $until);
        $this->SetTimerInterval('RateLimit', ($until - $now) * 1000);
        if ($this->GetStatus() !== self::STATUS_RATE_LIMITED) {
            $this->LogMessage(sprintf($this->Translate('The Pleos API limits the number of requests, pausing all requests until %s'), $this->rateLimitEnd()), KL_WARNING);
            $this->SetStatus(self::STATUS_RATE_LIMITED);
        }
    }

    /** The end of the running 429 pause in the local time zone, as log and debug output show it. */
    private function rateLimitEnd(): string
    {
        return date('Y-m-d H:i:s', (int) $this->GetBuffer('RateLimitedUntil'));
    }

    /**
     * Tells whether a 429 pause is running; an elapsed pause is ended and the status returns to active.
     */
    private function isRateLimited(): bool
    {
        $until = $this->GetBuffer('RateLimitedUntil');
        if ($until === '') {
            return false;
        }
        if ($this->currentTime() < (int) $until) {
            return true;
        }
        $this->SetBuffer('RateLimitedUntil', '');
        $this->SetTimerInterval('RateLimit', 0);
        if ($this->GetStatus() === self::STATUS_RATE_LIMITED) {
            $this->SetStatus(IS_ACTIVE);
        }
        return false;
    }

    /** @param mixed $decoded the decoded response body */
    private function errorCodeOf(mixed $decoded): string
    {
        $code = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['code'] ?? '') : '';
        return is_scalar($code) ? (string) $code : '';
    }

    /** @param mixed $decoded the decoded response body */
    private function describeError(int $status, mixed $decoded): string
    {
        $text = 'HTTP ' . $status;
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (!is_array($error)) {
            return $text;
        }
        $code = $this->errorCodeOf($decoded);
        if ($code !== '') {
            $text .= ', ' . $code;
        }
        if (isset($error['message']) && is_string($error['message'])) {
            $text .= ' ' . $error['message'];
        }
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            if (is_array($detail)) {
                $text .= sprintf(' (%s: %s)', (string) ($detail['field'] ?? ''), (string) ($detail['issue'] ?? ''));
            }
        }
        return $text;
    }

    /**
     * Removes the Client Secret and the Access Token from debug and log output and shortens VINs. The Authorization header is never passed in.
     * Response bodies are masked by request() before, see PleosPrivacy::maskJsonForDebug().
     */
    private function redact(string $text): string
    {
        foreach ([$this->ReadPropertyString('ClientSecret'), $this->GetBuffer('AccessToken')] as $secret) {
            if ($secret !== '') {
                $text = str_replace([$secret, json_encode($secret)], ['***', '"***"'], $text);
            }
        }
        $text = (string) preg_replace('/"(clientSecret|accessToken)"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/i', '"$1":"***"', $text);
        return PleosPrivacy::shortenVins($text);
    }
}
