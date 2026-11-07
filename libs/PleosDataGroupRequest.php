<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosApi.php';
include_once __DIR__ . '/PleosDataGroupResult.php';
include_once __DIR__ . '/PleosDataPointCatalog.php';
include_once __DIR__ . '/PleosPrivacy.php';

/**
 * Requests the Data Groups of the Pleos Vehicle's VIN for its three purposes: the poll, the Availability Check and the
 * diagnostics export. It hides the path below the VIN and the rules of the Trip Data Groups: their date range, its
 * fallback, the filter for new Trips and the hourly throttle during polling.
 *
 * Its state lives in the attributes `TripDays`, `LastTripStarts` and `TripFetchTimes`, which the host registers in
 * Create through registerDataGroupRequestAttributes(). The host uses PleosAccountClient for the requests and
 * PleosClock for the time.
 */
trait PleosDataGroupRequest
{
    /** The longest date range the API documents for Trips, in days including today. */
    private const MAX_TRIP_DAYS = 7;

    /** The date range to fall back to when the API refuses a longer one, and the one the diagnostics export requests. */
    private const FALLBACK_TRIP_DAYS = 2;

    /** Trip Data Groups are requested at most this often, in seconds, because their responses are large. */
    private const TRIP_INTERVAL = 3600;

    /** Registers the attributes that hold the state of the Trip Data Groups; the host calls it in Create. */
    private function registerDataGroupRequestAttributes(): void
    {
        // Per Trip Data Group: when it was last fetched, and the start of its last processed Trip.
        $this->RegisterAttributeString('TripFetchTimes', '{}');
        $this->RegisterAttributeString('LastTripStarts', '{}');
        // Shortened when the API refuses the documented date range.
        $this->RegisterAttributeInteger('TripDays', self::MAX_TRIP_DAYS);
    }

    /**
     * Requests the Data Groups of one poll in order. A Trip Data Group is skipped while it was fetched less than an hour
     * ago, and delivers only the Trips that started after the last processed one (no data when there is no new one).
     * The caller stops iterating once the Account could not ask.
     *
     * @param list<string> $groups
     * @return Generator<string, PleosDataGroupResult> Data Group => result
     */
    private function pollDataGroups(array $groups): Generator
    {
        $now = $this->currentTime();
        foreach ($groups as $group) {
            if (!PleosDataPointCatalog::isTripDataGroup($group)) {
                yield $group => $this->fetchDataGroup($group);
                continue;
            }
            // Trip responses are large, so each Trip Data Group is fetched at most hourly.
            $fetchTimes = json_decode($this->ReadAttributeString('TripFetchTimes'), true);
            if ($now - (int) ($fetchTimes[$group] ?? 0) < self::TRIP_INTERVAL) {
                continue;
            }
            $result = $this->fetchNewTrips($group);
            // A failed request is repeated at the next poll; only an answer with data or "No data" counts.
            if ($result->succeeded() || $result->noDataYet()) {
                $fetchTimes[$group] = $now;
                $this->WriteAttributeString('TripFetchTimes', json_encode($fetchTimes));
            }
            yield $group => $result;
        }
    }

    /** Requests one Data Group for the Availability Check: a Trip Data Group with all Trips of its date range. */
    private function checkDataGroup(string $group): PleosDataGroupResult
    {
        return PleosDataPointCatalog::isTripDataGroup($group) ? $this->fetchTripDataGroup($group, self::MAX_TRIP_DAYS) : $this->fetchDataGroup($group);
    }

    /**
     * Requests one Data Group for the diagnostics export, a Trip Data Group for the fallback range, and returns the raw
     * response. It sends no debug output.
     *
     * @return array{Path: string, Query: array<string, string>, Status: int, Body: mixed}
     */
    private function exportDataGroup(string $group): array
    {
        $path = $this->dataGroupPath($group);
        $query = PleosDataPointCatalog::isTripDataGroup($group) ? $this->tripRange(self::FALLBACK_TRIP_DAYS) : [];
        return ['Path' => $path, 'Query' => $query] + $this->apiRequest('GET', $path, $query);
    }

    /**
     * Requests a Trip Data Group from the day of the last processed Trip on and keeps only the Trips that started after
     * it; the data is null when there is no new Trip. The newest Trip is remembered as processed.
     */
    private function fetchNewTrips(string $group): PleosDataGroupResult
    {
        $lastStarts = json_decode($this->ReadAttributeString('LastTripStarts'), true);
        $lastStart = (int) ($lastStarts[$group] ?? 0);
        $result = $this->fetchTripDataGroup($group, self::MAX_TRIP_DAYS, $lastStart);
        if ($result->data === null) {
            return $result;
        }
        $trips = array_filter(PleosDataPointCatalog::trips($result->data), static fn (int $start): bool => $start > $lastStart, ARRAY_FILTER_USE_KEY);
        if ($trips === []) {
            $this->SendDebug($group, 'No new Trip', 0);
            return $result->withData(null);
        }
        $lastStarts[$group] = array_key_last($trips);
        $this->WriteAttributeString('LastTripStarts', json_encode($lastStarts));
        return $result->withData(['trips' => array_values($trips)] + $result->data);
    }

    /**
     * Requests a Trip Data Group for the last days, but not before the day of $since. If the API refuses a range
     * longer than the fallback, the fallback range is requested instead and used from then on.
     */
    private function fetchTripDataGroup(string $group, int $days, int $since = 0): PleosDataGroupResult
    {
        $days = min($days, $this->ReadAttributeInteger('TripDays'));
        $query = $this->tripRange($days, $since);
        $result = $this->fetchDataGroup($group, $query);
        $fallback = $this->tripRange(self::FALLBACK_TRIP_DAYS, $since);
        if ($query !== $fallback && $result->rangeRefused()) {
            $this->SendDebug($group, 'The API refused a range of ' . $days . ' days, falling back to ' . self::FALLBACK_TRIP_DAYS, 0);
            $this->WriteAttributeInteger('TripDays', self::FALLBACK_TRIP_DAYS);
            $result = $this->fetchDataGroup($group, $fallback);
        }
        return $result;
    }

    /**
     * Requests one Data Group, a Trip Data Group with the query of its date range. A failing Data Group never stops the others.
     *
     * @param array<string, string> $query
     */
    private function fetchDataGroup(string $group, array $query = []): PleosDataGroupResult
    {
        $path = $this->dataGroupPath($group);
        $response = $this->apiRequest('GET', $path, $query);
        // Errors such as "No Data" are debugged only; the Account logs outages.
        $this->SendDebug($group, PleosPrivacy::shortenVins('GET ' . $path . ' → HTTP ' . $response['Status'] . ' ' . json_encode(PleosPrivacy::maskForDebug($response['Body']), PleosApi::RAW_JSON_FLAGS)), 0);
        return PleosDataGroupResult::fromResponse($response);
    }

    /**
     * The query of a Trip date range: the last days including today, in UTC, but not before the day of $since.
     *
     * @return array{startDate: string, endDate: string}
     */
    private function tripRange(int $days, int $since = 0): array
    {
        $now = $this->currentTime();
        return ['startDate' => gmdate('Ymd', max($now - ($days - 1) * 86400, $since)), 'endDate' => gmdate('Ymd', $now)];
    }

    private function dataGroupPath(string $group): string
    {
        return '/vehicles/' . rawurlencode($this->ReadPropertyString('VIN')) . '/' . PleosDataPointCatalog::allDataGroups()[$group];
    }
}
