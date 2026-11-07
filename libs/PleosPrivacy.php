<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosApi.php';

/**
 * Keeps the identity and the position of a Vehicle out of debug output and diagnostics exports.
 */
final class PleosPrivacy
{
    /** Placeholder position in central Berlin, the same as in the test fixtures. */
    private const PLACEHOLDER_POSITION = ['latitude' => 52.52, 'longitude' => 13.405, 'altitude' => 34];

    /** What debug output shows instead of a nickname or a position. */
    private const DEBUG_MASK = ['nickname' => '***', 'latitude' => '***', 'longitude' => '***', 'altitude' => '***'];

    /**
     * Anonymizes the raw responses of a diagnostics export: every VIN of the vehicle list and the own VIN become a
     * placeholder, nicknames become "Test vehicle" (own) and "Test vehicle n", in the path and the body of each
     * response, and positions become a placeholder in central Berlin.
     *
     * @param mixed $vehicleList the decoded body of the vehicle list (/vehicles/consent)
     * @param list<array{Path: string, Query: array<string, string>, Status: int, Body: mixed}> $exchanges
     *
     * @return list<array{Path: string, Query: array<string, string>, Status: int, Body: mixed}>
     */
    public static function anonymizeDiagnostics(string $vin, mixed $vehicleList, array $exchanges): array
    {
        $replacements = self::placeholderIdentities($vin, $vehicleList);
        return array_map(static fn (array $exchange): array => [
            'Path' => strtr($exchange['Path'], $replacements),
            'Body' => self::anonymize($exchange['Body'], $replacements),
        ] + $exchange, $exchanges);
    }

    /** A decoded response body as debug output shows it: without nicknames and positions. */
    public static function maskForDebug(mixed $body): mixed
    {
        return self::anonymize($body, [], self::DEBUG_MASK);
    }

    /** A raw response body as debug output shows it, see maskForDebug(); a body that is not JSON is kept. */
    public static function maskJsonForDebug(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }
        return (string) json_encode(self::maskForDebug($decoded), PleosApi::RAW_JSON_FLAGS);
    }

    /** Shortens every VIN in a text to its first 3 and last 4 characters, so shared output does not identify the Vehicle. */
    public static function shortenVins(string $text): string
    {
        return (string) preg_replace('/\b([A-HJ-NPR-Z0-9]{3})[A-HJ-NPR-Z0-9]{10}([A-HJ-NPR-Z0-9]{4})\b/', '$1…$2', $text);
    }

    /**
     * Replaces the given texts in every string, and every number or string under a key of $fields by its value there.
     * Sample arrays (Trip Data Groups) inherit the field name of their parent.
     *
     * @param array<string, string> $replacements
     * @param array<string, mixed> $fields field name => replacement value
     */
    private static function anonymize(mixed $value, array $replacements, array $fields = self::PLACEHOLDER_POSITION, string $key = ''): mixed
    {
        if (array_key_exists($key, $fields) && (is_int($value) || is_float($value) || is_string($value))) {
            return $fields[$key];
        }
        if (is_string($value)) {
            return $replacements === [] ? $value : strtr($value, $replacements);
        }
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $childKey => $child) {
            $result[$childKey] = self::anonymize($child, $replacements, $fields, is_int($childKey) ? $key : (string) $childKey);
        }
        return $result;
    }

    /**
     * Maps every VIN and nickname of the vehicle list, and the own VIN, to a placeholder; the own Vehicle is number 1.
     *
     * @return array<string, string>
     */
    private static function placeholderIdentities(string $vin, mixed $vehicleList): array
    {
        $vehicles = $vehicleList['data']['vehicles'] ?? [];
        $vehicles = is_array($vehicles) ? array_values(array_filter($vehicles, 'is_array')) : [];
        usort($vehicles, static fn (array $a, array $b): int => (($b['vin'] ?? null) === $vin) <=> (($a['vin'] ?? null) === $vin));
        $replacements = [$vin => self::placeholderVin($vin, 1)];
        $number = 1;
        foreach ($vehicles as $vehicle) {
            $own = ($vehicle['vin'] ?? null) === $vin;
            $current = $own ? 1 : ++$number;
            if (is_string($vehicle['vin'] ?? null) && $vehicle['vin'] !== '') {
                $replacements[$vehicle['vin']] = self::placeholderVin($vehicle['vin'], $current);
            }
            if (is_string($vehicle['nickname'] ?? null) && $vehicle['nickname'] !== '') {
                $replacements[$vehicle['nickname']] = $own ? 'Test vehicle' : 'Test vehicle ' . $current;
            }
        }
        unset($replacements['']);
        return $replacements;
    }

    /** A made-up VIN that keeps the manufacturer code (first 3 characters) and numbers the Vehicles of an export. */
    private static function placeholderVin(string $vin, int $number): string
    {
        return substr($vin, 0, 3) . 'TEST' . sprintf('%010d', $number);
    }
}
