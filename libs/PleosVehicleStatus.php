<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosApi.php';
include_once __DIR__ . '/PleosDataGroupResult.php';

/**
 * The rule for the Pleos Vehicle's status, in every situation that decides it: applying the configuration, the end of
 * a poll and a status change of the Pleos Account. Each answer is the new status, or null to leave it unchanged.
 *
 * The connected Pleos Account comes in as its instance status, null when none is connected.
 */
final class PleosVehicleStatus
{
    /** No Pleos Account connected, or one that is neither active nor rate limited. */
    public const NO_ACTIVE_ACCOUNT = 201;

    /** The API Key no longer covers this Vehicle. */
    public const NOT_COVERED = 202;

    /** The Pleos Account pauses after a 429. */
    public const ACCOUNT_RATE_LIMITED = 203;

    /**
     * After applying the configuration: inactive without a VIN, else the status the Account implies or active.
     * This clears a "not covered" status until the next poll.
     */
    public static function whenApplied(bool $vinSet, ?int $accountStatus): int
    {
        if (!$vinSet) {
            return IS_INACTIVE;
        }
        return self::accountImplies($accountStatus) ?? IS_ACTIVE;
    }

    /**
     * At the end of a poll, or of one that could not start for want of a connected Account (no results). A poll without
     * any answer from the API (server error, timeout) leaves the status unchanged, except for the errors the Account
     * caused.
     *
     * @param list<PleosDataGroupResult> $results the result of each requested Data Group
     */
    public static function afterPoll(?int $accountStatus, array $results, int $current): ?int
    {
        $implied = self::accountImplies($accountStatus);
        if ($implied !== null) {
            return $implied;
        }
        if (array_filter($results, static fn (PleosDataGroupResult $result): bool => $result->notCovered()) !== []) {
            return self::NOT_COVERED;
        }
        if (array_filter($results, static fn (PleosDataGroupResult $result): bool => !$result->unreachable()) !== []) {
            return IS_ACTIVE;
        }
        return self::clearAccountError($current);
    }

    /** After the Account's status changed, or another Account was connected: an active Account clears only the errors it caused. */
    public static function whenAccountChanged(bool $vinSet, ?int $accountStatus, int $current): ?int
    {
        if (!$vinSet) {
            return null;
        }
        return self::accountImplies($accountStatus) ?? self::clearAccountError($current);
    }

    /** The status the Account implies, or null while it is active. */
    private static function accountImplies(?int $accountStatus): ?int
    {
        return match ($accountStatus) {
            IS_ACTIVE                      => null,
            PleosApi::ACCOUNT_RATE_LIMITED => self::ACCOUNT_RATE_LIMITED,
            default                        => self::NO_ACTIVE_ACCOUNT,
        };
    }

    private static function clearAccountError(int $current): ?int
    {
        return in_array($current, [self::NO_ACTIVE_ACCOUNT, self::ACCOUNT_RATE_LIMITED], true) ? IS_ACTIVE : null;
    }
}
