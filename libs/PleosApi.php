<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosHttpResponse.php';

/**
 * Facts about the Pleos API that the Pleos Account and its children share.
 */
final class PleosApi
{
    /** The data flow between the Pleos Account and its children, see PleosAccountClient. */
    public const DATA_ID = '{EE23C3DC-C9D9-F25B-5589-F4A4FD6AE524}';

    /** The Pleos Account's status while a 429 pause runs, which its children report as their own. */
    public const ACCOUNT_RATE_LIMITED = 203;

    /** How raw API JSON is written for people to read (debug output, the diagnostics export, the raw Trip JSON). */
    public const RAW_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Whether the API was not reachable: no response at all (timeout, DNS, TLS, or not sent) or a server error. */
    public static function isUnreachable(int $status): bool
    {
        return $status === PleosHttpResponse::NO_RESPONSE || $status >= 500;
    }
}
