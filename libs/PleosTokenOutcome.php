<?php

declare(strict_types=1);

if (!enum_exists('PleosTokenOutcome')) {
    /**
     * How an Access Token request of the Pleos Account ended.
     */
    enum PleosTokenOutcome
    {
        case Valid;

        /** The API blamed the credentials: Client ID, Client Secret, Brand or API Key. */
        case InvalidApiKey;

        case RateLimited;

        /** No response or a server error. */
        case Unreachable;

        /** Any other refusal, such as a 403: taken as temporary. */
        case Refused;
    }
}
