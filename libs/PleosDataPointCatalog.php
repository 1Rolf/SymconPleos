<?php

declare(strict_types=1);

/**
 * Every Data Point the Pleos Vehicle knows, as declarative data.
 *
 * A Data Point entry (keyed by its variable ident, in display order) has:
 * - `group`:    its Data Group (a key of DATA_GROUPS)
 * - `name`:     English variable name, translated through the module's locale.json
 * - `path`:     where the raw value sits in the response's `data`, see resolve()
 * - `derive`:   instead of `path`, for a Derived Data Point: the derivation's name and arguments, see derive()
 * - `type`:     `float`, `integer`, `boolean`, `datetime` (Unix time from a Report Time string), `enumeration` or
 *               `map` (HTML embedding OpenStreetMap at the WGS84 position of a `location` object); what each type
 *               means is defined in PleosDataPointType
 * - `options`:  for `enumeration`: raw API value => caption; the variable stores the raw value in the type the API
 *               sends it (ADR 0003). For `boolean`: [false caption, true caption].
 * - `quantity`: a key of QUANTITIES, for values whose unit the Vehicle reports. `unitPath` points at the reported
 *               unit (relative to `data`), `unit` gives a fixed unit instead. `mileIsKmOption` names a boolean property
 *               of the Pleos Vehicle that, when set, treats a reported `mile`/`miles` as km (odometer quirk).
 * - `scale`:    factor applied to the raw number (e.g. kJ → kWh)
 * - `suffix`, `digits`, `min`, `max`, `icon`: presentation
 * - `default`:  false keeps the Data Point out of the default selection, even within a core Data Group
 * - `note`:     English note the selection form shows next to the Data Point, translated through locale.json
 * - `notFor`:   Vehicle types (`type` of the vehicle list) the Data Point does not fit: the Availability Check never
 *               proposes it for them, although it stays selectable
 * - `timing`:   true for a Report Time, Trip start or Trip duration: it tells when, not what, so the Availability Check
 *               does not propose it alone
 * - `invalidFrom`: raw values at or above it mean "no value"
 *
 * Data Points of a Trip Data Group read the last Trip of the response (see lastTrip()); their `path` is relative to
 * the Trip. With `aggregate`, the path points at a sample array whose valid samples are decoded one by one (with the
 * unit sample at the same position of `unitPath`) and reduced to one key figure: `min`, `max`, `maxAbs`, `average`,
 * `delta` (last minus first) or `last`. The type `duration` is a number of seconds, `json` the raw JSON of the Trip
 * (it has neither `path` nor `derive`, so it reads the whole Trip).
 *
 * The Pleos Vehicle handles Data Points only as PleosDataPoint, built from these entries.
 */
final class PleosDataPointCatalog
{
    /** Data Group => endpoint below /vehicles/{vin}/ */
    public const DATA_GROUPS = [
        'Battery'    => 'batteries',
        'Powertrain' => 'powertrains',
        'Driving'    => 'driving',
        'Status'     => 'status',
        'Location'   => 'locations',
        'Freezer'    => 'freezer',
    ];

    /** Trip Data Group => endpoint below /vehicles/{vin}/; they are requested for a date range. */
    public const TRIP_DATA_GROUPS = [
        'Trip Powertrain' => 'powertrains/data',
        'Trip Speed'      => 'speed',
        'Trip Driving'    => 'driving/data',
        'Trip Status'     => 'status/data',
    ];

    /** Data Groups whose Data Points a new Pleos Vehicle has selected. */
    public const CORE_DATA_GROUPS = ['Battery', 'Powertrain', 'Driving', 'Status', 'Location'];

    /**
     * Unit quantities, each converted through a base unit (its default unit: km, km/h, °C, bar):
     * - `property`:    the Pleos Vehicle property that holds the owner's choice
     * - `units`:       the units the owner can choose, the first being the default: unit => `suffix`, `factor` from the
     *                  base unit (or [factor, offset] for affine units such as °F) and optionally `digits`
     * - `reported`:    the units a Vehicle reports, compared case-insensitively: reported unit => factor into the base
     *                  unit (or [factor, offset]). For tyre pressures the factor includes the raw coding (kpa ×5, bar ÷10).
     * - `invalidFrom`: raw values at or above it mean "no value"
     */
    public const QUANTITIES = [
        'distance'    => [
            'property' => 'DistanceUnit',
            'units'    => ['km' => ['suffix' => ' km', 'factor' => 1.0], 'mi' => ['suffix' => ' mi', 'factor' => 1 / self::KM_PER_MILE]],
            'reported' => ['km' => 1.0, 'mile' => self::KM_PER_MILE, 'miles' => self::KM_PER_MILE, 'meter' => 0.001, 'feet' => 0.0003048],
        ],
        'speed'       => [
            'property' => 'SpeedUnit',
            'units'    => ['km/h' => ['suffix' => ' km/h', 'factor' => 1.0], 'mph' => ['suffix' => ' mph', 'factor' => 1 / self::KM_PER_MILE]],
            'reported' => ['kph' => 1.0, 'mph' => self::KM_PER_MILE, 'mps' => 3.6],
        ],
        'temperature' => [
            'property' => 'TemperatureUnit',
            'units'    => ['C' => ['suffix' => ' °C', 'factor' => 1.0], 'F' => ['suffix' => ' °F', 'factor' => [1.8, 32.0]]],
            'reported' => ['C' => 1.0, 'F' => [5 / 9, -160 / 9]],
        ],
        'pressure'    => [
            'property'    => 'PressureUnit',
            'units'       => [
                'bar' => ['suffix' => ' bar', 'factor' => 1.0],
                'kPa' => ['suffix' => ' kPa', 'factor' => 100.0, 'digits' => 0],
                'psi' => ['suffix' => ' psi', 'factor' => self::PSI_PER_BAR],
            ],
            'reported'    => ['psi' => 1 / self::PSI_PER_BAR, 'kpa' => 0.05, 'bar' => 0.1],
            'invalidFrom' => 255,
        ],
    ];

    private const KM_PER_MILE = 1.609344;
    private const PSI_PER_BAR = 14.503773773;

    /** The documented codes of the Status Data Group's `warnings` list => English variable name */
    private const WARNINGS = [
        'lowFuel'                => 'Warning low fuel',
        'smartKeyBattery'        => 'Warning smart key battery',
        'washFluid'              => 'Warning washer fluid',
        'brakeOil'               => 'Warning brake fluid',
        'engineOil'              => 'Warning engine oil',
        'tirePressureFrontLeft'  => 'Warning tire pressure front left',
        'tirePressureFrontRight' => 'Warning tire pressure front right',
        'tirePressureRearLeft'   => 'Warning tire pressure rear left',
        'tirePressureRearRight'  => 'Warning tire pressure rear right',
        'tirePressureLow'        => 'Warning low tire pressure',
        'frontHeadLamp'          => 'Warning headlamp',
        'frontLeftTurnSignal'    => 'Warning turn signal front left',
        'frontRightTurnSignal'   => 'Warning turn signal front right',
        'rearLeftStopLamp'       => 'Warning brake light rear left',
        'rearRightStopLamp'      => 'Warning brake light rear right',
        'rearLeftTurnSignal'     => 'Warning turn signal rear left',
        'rearRightTurnSignal'    => 'Warning turn signal rear right',
    ];

    /** The fields each derivation reads, see unknownFields(); `flag` reads the path it is given. */
    private const DERIVATION_FIELDS = [
        'allDoorsLocked' => ['doors[].left', 'doors[].right'],
        'warning'        => ['warnings'],
        'warningCount'   => ['warnings'],
        'range'          => ['distanceToEmpties[].type', 'distanceToEmpties[].value', 'distanceToEmpties[].unit'],
    ];

    /** Warnings that do not fit a Vehicle without a combustion engine. */
    private const COMBUSTION_WARNINGS = ['lowFuel', 'engineOil'];

    private const ON_OFF = ['off' => 'Off', 'on' => 'On'];
    private const CLOSED_OPEN = ['closed' => 'Closed', 'open' => 'Open'];
    private const DOOR = ['closed' => 'Closed', 'open' => 'Open', 'locked' => 'Locked'];
    private const SEAT_LEVEL = ['off' => 'Off', 'low' => 'Low', 'middle' => 'Middle', 'high' => 'High'];
    private const NO_YES = ['No', 'Yes'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $dataPoints = null;

    /** @return array<string, array<string, mixed>> ident => Data Point */
    public static function dataPoints(): array
    {
        return self::$dataPoints ??= array_merge(
            self::battery(),
            self::powertrain(),
            self::driving(),
            self::status(),
            self::location(),
            self::freezer(),
            self::tripPowertrain(),
            self::tripSpeed(),
            self::tripDriving(),
            self::tripStatus(),
        );
    }

    /** @return array<string, string> every Data Group => its endpoint below /vehicles/{vin}/, the Trip Data Groups last */
    public static function allDataGroups(): array
    {
        return self::DATA_GROUPS + self::TRIP_DATA_GROUPS;
    }

    public static function isTripDataGroup(string $group): bool
    {
        return isset(self::TRIP_DATA_GROUPS[$group]);
    }

    /**
     * The Trips of a Trip Data Group's `data` with a valid start, keyed by their start as Unix time and sorted by it.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public static function trips(array $data): array
    {
        $trips = [];
        foreach (is_array($data['trips'] ?? null) ? $data['trips'] : [] as $trip) {
            $start = is_array($trip) ? self::parseUtcTime($trip['triggerStartTime'] ?? null) : null;
            if ($start !== null) {
                $trips[$start] = $trip;
            }
        }
        ksort($trips);
        return $trips;
    }

    /**
     * The Trip that started last, or null if there is none.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function lastTrip(array $data): ?array
    {
        $trips = self::trips($data);
        return $trips === [] ? null : end($trips);
    }

    /**
     * Parses a Report Time or Trip start in UTC, with or without milliseconds ("2026-11-03T16:36:19Z", "…T16:35:24.794Z"),
     * into Unix time.
     */
    public static function parseUtcTime(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        foreach (['Y-m-d\TH:i:s.v\Z', 'Y-m-d\TH:i:s\Z'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
            if ($time !== false) {
                return $time->getTimestamp();
            }
        }
        return null;
    }

    /**
     * Converts a value from the unit a Vehicle reported into a unit of its quantity (the default unit if the quantity
     * does not offer it).
     *
     * @return float|null null if the reported unit is unknown or the raw value means "no value"
     */
    public static function convert(string $quantity, float $value, string $reportedUnit, string $unit): ?float
    {
        $definition = self::QUANTITIES[$quantity];
        $conversion = self::reportedConversion($quantity, $reportedUnit);
        if ($conversion === null || (isset($definition['invalidFrom']) && $value >= $definition['invalidFrom'])) {
            return null;
        }
        return self::apply($definition['units'][self::unit($quantity, $unit)]['factor'], self::apply($conversion, $value));
    }

    /** Whether values reported in this unit can be converted into the quantity's units. */
    public static function isReportedUnit(string $quantity, string $reportedUnit): bool
    {
        return self::reportedConversion($quantity, $reportedUnit) !== null;
    }

    /** Returns the unit if the quantity offers it, else the quantity's default unit. */
    public static function unit(string $quantity, string $unit): string
    {
        $units = self::QUANTITIES[$quantity]['units'];
        return isset($units[$unit]) ? $unit : (string) array_key_first($units);
    }

    /**
     * Parses a raw number. The API sends some numbers as strings (range: `"175"`); `"-"`, `"invalid"` and anything else
     * that is not a number mean no value.
     */
    public static function number(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        return is_string($raw) && is_numeric(trim($raw)) ? (float) trim($raw) : null;
    }

    /** @return list<string> idents a new Pleos Vehicle has selected */
    public static function defaultSelection(): array
    {
        $idents = [];
        foreach (self::dataPoints() as $ident => $dataPoint) {
            if (in_array($dataPoint['group'], self::CORE_DATA_GROUPS, true) && ($dataPoint['default'] ?? true)) {
                $idents[] = $ident;
            }
        }
        return $idents;
    }

    /** @return list<string> the boolean properties of the Pleos Vehicle that Data Points name as `mileIsKmOption` */
    public static function mileIsKmOptions(): array
    {
        return array_values(array_unique(array_filter(array_column(self::dataPoints(), 'mileIsKmOption'))));
    }

    /**
     * Reads the raw value of a Derived Data Point from the response's `data` through its derivation.
     *
     * @param list<mixed> $arguments
     * @param array<string, mixed> $data
     * @return array{bool, mixed, mixed} whether there is a value, the raw value and its reported unit (null if none)
     */
    public static function derive(string $derivation, array $arguments, array $data): array
    {
        return self::$derivation($data, ...$arguments);
    }

    /**
     * The fields of a Data Group's `data` that no Data Point reads, as paths in which list entries are `[]`
     * (e.g. `windows[].left`). A list of plain values (such as `warnings`) is one field. A known parent object without
     * values (`null`, `{}` or `[]`, e.g. the EV2's `"freezer": []`) is not an unknown field. The root path `''` is never a field, so an empty `data` reports none.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function unknownFields(string $group, array $data): array
    {
        $known = self::knownFields($group);
        $parents = self::knownParents($known);
        $unknown = [];
        foreach (self::fieldPathEmptiness($data, '') as $path => $empty) {
            $emptyKnownParent = $empty && isset($parents[$path]);
            // The root path '' is never a field.
            if ($path !== '' && !isset($known[$path]) && !$emptyKnownParent) {
                $unknown[] = (string) $path;
            }
        }
        return $unknown;
    }

    /**
     * Finds the value at a path such as `charge.stateOfCharge`, `doors[1].left` or `batteries[type=main].remainRatio`
     * (the first array entry whose `type` is `main`).
     *
     * @return array{bool, mixed} whether the path exists, and its value
     */
    public static function resolve(mixed $data, string $path): array
    {
        foreach (explode('.', $path) as $segment) {
            if (!preg_match('/^(\w+)(?:\[(?:(\d+)|(\w+)=(\w+))\])?$/', $segment, $match)) {
                throw new InvalidArgumentException('Invalid Data Point path ' . $path);
            }
            if (!is_array($data) || !array_key_exists($match[1], $data)) {
                return [false, null];
            }
            $data = $data[$match[1]];
            if (($match[2] ?? '') !== '') {
                if (!is_array($data) || !array_key_exists((int) $match[2], $data)) {
                    return [false, null];
                }
                $data = $data[(int) $match[2]];
            } elseif (($match[3] ?? '') !== '') {
                $entries = is_array($data) ? array_values(array_filter($data, static fn (mixed $entry): bool => is_array($entry) && ($entry[$match[3]] ?? null) === $match[4])) : [];
                if ($entries === []) {
                    return [false, null];
                }
                $data = $entries[0];
            }
        }
        return [true, $data];
    }

    /** @return array<string, true> the fields the Data Points of a Data Group read, see unknownFields() */
    private static function knownFields(string $group): array
    {
        $known = [];
        // The fields of a Trip sit in the `trips` list.
        $tripPrefix = self::isTripDataGroup($group) ? 'trips[].' : '';
        foreach (self::dataPoints() as $dataPoint) {
            if ($dataPoint['group'] !== $group) {
                continue;
            }
            $paths = [$dataPoint['path'] ?? null, $dataPoint['unitPath'] ?? null];
            if (isset($dataPoint['derive'])) {
                $paths = array_merge($paths, $dataPoint['derive'][0] === 'flag' ? [$dataPoint['derive'][1]] : self::DERIVATION_FIELDS[$dataPoint['derive'][0]]);
            }
            foreach (array_filter($paths, 'is_string') as $path) {
                $path = $tripPrefix . $path;
                // A selector such as `batteries[type=main]` reads the selector field too.
                if (preg_match_all('/(?:^|\.)([\w.]*?\w+)\[(\w+)=\w+\]/', $path, $selectors, PREG_SET_ORDER)) {
                    foreach ($selectors as $selector) {
                        $prefix = substr($path, 0, (int) strpos($path, $selector[0]) + strlen($selector[0]));
                        $known[self::normalizePath(substr($prefix, 0, strrpos($prefix, '[')) . '[].' . $selector[2])] = true;
                    }
                }
                $known[self::normalizePath($path)] = true;
            }
        }
        return $known;
    }

    /**
     * The parents of known field paths: those of `trips[].location.speed` are `trips`, `trips[]` and `trips[].location`.
     *
     * @param array<string, true> $known
     * @return array<string, true>
     */
    private static function knownParents(array $known): array
    {
        $parents = [];
        foreach (array_keys($known) as $path) {
            $segments = explode('.', (string) $path);
            for ($count = count($segments) - 1; $count > 0; $count--) {
                $parent = implode('.', array_slice($segments, 0, $count));
                $parents[$parent] = true;
                $parents[(string) preg_replace('/\[\]$/', '', $parent)] = true;
            }
        }
        return $parents;
    }

    private static function normalizePath(string $path): string
    {
        return (string) preg_replace('/\[[^\]]*\]/', '[]', $path);
    }

    /**
     * The field paths of a value, each with whether the field is empty: `null`, `{}`, `[]` or a list of only `null`.
     * See unknownFields().
     *
     * @return array<string, bool>
     */
    private static function fieldPathEmptiness(mixed $value, string $prefix): array
    {
        if (!is_array($value) || $value === []) {
            return [$prefix => $value === null || $value === []];
        }
        $children = [];
        if (array_is_list($value)) {
            if (array_filter($value, static fn (mixed $entry): bool => $entry !== null) === []) {
                return [$prefix => true];
            }
            $entries = array_filter($value, 'is_array');
            if ($entries === []) {
                // A list of plain values (such as `warnings`) is one field with a value.
                return [$prefix => false];
            }
            foreach ($entries as $entry) {
                $children[] = self::fieldPathEmptiness($entry, $prefix . '[]');
            }
        } else {
            foreach ($value as $key => $child) {
                $children[] = self::fieldPathEmptiness($child, $prefix === '' ? (string) $key : $prefix . '.' . $key);
            }
        }
        $paths = [];
        foreach ($children as $child) {
            foreach ($child as $path => $empty) {
                // A field is empty only if it is empty in every list entry.
                $paths[$path] = ($paths[$path] ?? true) && $empty;
            }
        }
        return $paths;
    }

    /**
     * A boolean from a string value: false for one known value, true for another, no value for anything else.
     *
     * @param array<string, mixed> $data
     * @return array{bool, mixed, null}
     */
    private static function flag(array $data, string $path, string $false, string $true): array
    {
        $raw = self::resolve($data, $path)[1];
        return in_array($raw, [$false, $true], true) ? [true, $raw === $true, null] : [false, null, null];
    }

    /**
     * "Vehicle locked": true if all doors are locked, false if any door is not, no value if that cannot be told.
     *
     * @param array<string, mixed> $data
     * @return array{bool, mixed, null}
     */
    private static function allDoorsLocked(array $data): array
    {
        $states = [];
        foreach (self::positions() as [$row, $side]) {
            $states[] = self::resolve($data, 'doors[' . $row . '].' . $side)[1];
        }
        if (array_intersect($states, ['open', 'closed']) !== []) {
            return [true, false, null];
        }
        return count(array_keys($states, 'locked', true)) === count($states) ? [true, true, null] : [false, null, null];
    }

    /**
     * One warning: whether the `warnings` list contains its code.
     *
     * @param array<string, mixed> $data
     * @return array{bool, mixed, null}
     */
    private static function warning(array $data, string $code): array
    {
        $warnings = self::warnings($data);
        return $warnings === null ? [false, null, null] : [true, in_array($code, $warnings, true), null];
    }

    /**
     * "Number of warnings": the number of distinct codes in the `warnings` list, including undocumented ones.
     *
     * @param array<string, mixed> $data
     * @return array{bool, mixed, null}
     */
    private static function warningCount(array $data): array
    {
        $warnings = self::warnings($data);
        return $warnings === null ? [false, null, null] : [true, count(array_unique($warnings)), null];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>|null the active warning codes, or null if the list is missing or holds invalid entries
     */
    private static function warnings(array $data): ?array
    {
        $warnings = $data['warnings'] ?? null;
        if (!is_array($warnings) || !array_is_list($warnings)) {
            return null;
        }
        foreach ($warnings as $code) {
            if (!is_string($code) || $code === 'invalid') {
                return null;
            }
        }
        return $warnings;
    }

    /**
     * The range of one `distanceToEmpties` type, parsed from its string by number(). Plug-in hybrids report two `PHEV`
     * entries: the larger is the total range and the smaller the electric range, so both need a value to tell them apart.
     *
     * @param array<string, mixed> $data
     * @param string|null $phevRange `total` or `electric` for the type `PHEV`
     * @return array{bool, mixed, mixed}
     */
    private static function range(array $data, string $type, ?string $phevRange = null): array
    {
        $entries = $data['distanceToEmpties'] ?? null;
        $entries = is_array($entries) ? array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && ($entry['type'] ?? null) === $type)) : [];
        $ranges = [];
        foreach ($entries as $entry) {
            $value = self::number($entry['value'] ?? null);
            $unit = $entry['unit'] ?? null;
            if ($value === null) {
                return [false, null, null];
            }
            // Compared in km, so entries in different units are told apart correctly.
            $km = is_string($unit) && self::isReportedUnit('distance', $unit) ? self::convert('distance', $value, $unit, 'km') : null;
            $ranges[] = [$value, $unit, $km];
        }
        if ($phevRange === null) {
            return $ranges === [] ? [false, null, null] : [true, $ranges[0][0], $ranges[0][1]];
        }
        if (count($ranges) !== 2 || in_array(null, array_column($ranges, 2), true)) {
            return [false, null, null];
        }
        usort($ranges, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
        [$value, $unit] = $ranges[$phevRange === 'total' ? 1 : 0];
        return [true, $value, $unit];
    }

    /** @return array<string, array<string, mixed>> */
    private static function battery(): array
    {
        return self::group('Battery', [
            'BatteryReportTime'     => self::reportTime('Battery Report Time'),
            'StateOfCharge'         => ['name' => 'State of charge', 'path' => 'charge.stateOfCharge', 'type' => 'float', 'suffix' => ' %', 'digits' => 1, 'min' => 0, 'max' => 100, 'icon' => 'battery-full'],
            'PlugStatus'            => ['name' => 'Charging cable', 'derive' => ['flag', 'charge.plugin', 'disconnected', 'connected'], 'type' => 'boolean', 'options' => ['Disconnected', 'Connected'], 'icon' => 'plug'],
            'ChargeStatus'          => ['name' => 'Charge status', 'path' => 'charge.status', 'type' => 'enumeration', 'icon' => 'charging-station', 'options' => [
                'notCharging'      => 'Not charging',
                'charging'         => 'Charging (AC)',
                'fastCharging'     => 'Fast charging',
                'v2lOperating'     => 'V2L operating',
                'v2lStop'          => 'V2L stopped',
                'v2xOperating'     => 'V2X operating',
                'reservedCharging' => 'Scheduled charging',
                'wirelessCharging' => 'Wireless charging',
            ]],
            'Charging'              => ['name' => 'Charging', 'path' => 'charge.charging', 'type' => 'boolean', 'options' => self::NO_YES, 'icon' => 'bolt'],
            // The API reports the capacity in kJ (Kia EV2: 151920 = 42.2 kWh).
            'BatteryCapacity'       => ['name' => 'Battery capacity', 'path' => 'charge.batteryCapacity', 'type' => 'float', 'scale' => 1 / 3600, 'suffix' => ' kWh', 'digits' => 1, 'icon' => 'battery-full'],
            'TargetStateOfChargeAC' => ['name' => 'Charge limit AC', 'path' => 'charge.targetStateOfCharge.standard', 'type' => 'integer', 'suffix' => ' %', 'min' => 0, 'max' => 100, 'icon' => 'bullseye'],
            'TargetStateOfChargeDC' => ['name' => 'Charge limit DC', 'path' => 'charge.targetStateOfCharge.quick', 'type' => 'integer', 'suffix' => ' %', 'min' => 0, 'max' => 100, 'icon' => 'bullseye'],
            'RemainingChargeTime'   => ['name' => 'Remaining charge time', 'path' => 'charge.remainTime', 'type' => 'integer', 'suffix' => ' min', 'icon' => 'hourglass'],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function powertrain(): array
    {
        $range = ['type' => 'float', 'quantity' => 'distance', 'digits' => 0, 'min' => 0, 'icon' => 'route'];
        return self::group('Powertrain', [
            'PowertrainReportTime'    => self::reportTime('Powertrain Report Time'),
            'FuelLevel'               => ['name' => 'Fuel level', 'path' => 'engine.fuelLevel', 'notFor' => ['EV'], 'type' => 'float', 'suffix' => ' %', 'digits' => 0, 'min' => 0, 'max' => 100, 'icon' => 'gas-pump'],
            'HighVoltageBatteryLevel' => ['name' => 'High-voltage battery level', 'path' => 'batteries[type=main].remainRatio', 'type' => 'float', 'suffix' => ' %', 'digits' => 0, 'min' => 0, 'max' => 100, 'icon' => 'battery-full'],
            'RangeEV'                 => ['name' => 'Range (EV)', 'derive' => ['range', 'EV']] + $range,
            'RangeICE'                => ['name' => 'Range (ICE)', 'derive' => ['range', 'ICE'], 'notFor' => ['EV']] + $range,
            'RangeHEV'                => ['name' => 'Range (HEV)', 'derive' => ['range', 'HEV'], 'notFor' => ['EV']] + $range,
            'RangePHEVTotal'          => ['name' => 'Total range (PHEV)', 'derive' => ['range', 'PHEV', 'total'], 'notFor' => ['EV']] + $range,
            'RangePHEVElectric'       => ['name' => 'Electric range (PHEV)', 'derive' => ['range', 'PHEV', 'electric'], 'notFor' => ['EV']] + $range,
            'RangeFCEV'               => ['name' => 'Range (FCEV)', 'derive' => ['range', 'FCEV']] + $range,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function driving(): array
    {
        return self::group('Driving', [
            'DrivingReportTime' => self::reportTime('Driving Report Time'),
            'Ignition'          => ['name' => 'Ignition', 'path' => 'ignition.status', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'key'],
            'IgnitionIGN1'      => ['name' => 'Ignition (IGN1)', 'path' => 'ignition.ignitionDetails[type=ign1].status', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'key'],
            'HighVoltageSystem' => ['name' => 'High-voltage system (IGN3)', 'path' => 'ignition.ignitionDetails[type=ign3].status', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'bolt'],
            'AccessoryPower'    => ['name' => 'Accessory power (ACC)', 'path' => 'ignition.ignitionDetails[type=acc].status', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'plug'],
            'SleepMode'         => ['name' => 'Sleep mode', 'path' => 'sleepMode', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'moon'],
            'Odometer'          => ['name' => 'Odometer', 'path' => 'odometer.value', 'type' => 'float', 'quantity' => 'distance', 'unitPath' => 'odometer.unit', 'mileIsKmOption' => 'OdometerMileIsKm', 'digits' => 1, 'icon' => 'road'],
            'ParkingPosition'   => ['name' => 'In park position', 'path' => 'transmission.parkingPosition', 'type' => 'boolean', 'options' => self::NO_YES, 'icon' => 'square-parking'],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function status(): array
    {
        $points = ['StatusReportTime' => self::reportTime('Status Report Time')];
        foreach (self::positions() as $position => [$row, $side, $caption]) {
            $points['Door' . $position] = ['name' => 'Door ' . $caption, 'path' => 'doors[' . $row . '].' . $side, 'type' => 'enumeration', 'options' => self::DOOR, 'icon' => 'door-open'];
        }
        $points['VehicleLocked'] = ['name' => 'Vehicle locked', 'derive' => ['allDoorsLocked'], 'type' => 'boolean', 'options' => self::NO_YES, 'icon' => 'lock'];
        $points['Hood'] = ['name' => 'Hood', 'path' => 'hood', 'type' => 'enumeration', 'options' => self::CLOSED_OPEN, 'icon' => 'car'];
        $points['Trunk'] = ['name' => 'Trunk', 'path' => 'trunk', 'type' => 'enumeration', 'options' => self::CLOSED_OPEN, 'icon' => 'car'];
        $points['Sunroof'] = ['name' => 'Sunroof', 'path' => 'sunroof', 'type' => 'enumeration', 'options' => self::CLOSED_OPEN, 'icon' => 'sun'];
        foreach (self::positions() as $position => [$row, $side, $caption]) {
            $points['TirePressure' . $position] = ['name' => 'Tire pressure ' . $caption, 'path' => 'tire[' . $row . '].' . $side, 'type' => 'float', 'quantity' => 'pressure', 'unitPath' => 'tire[' . $row . '].unit', 'digits' => 1, 'icon' => 'gauge'];
        }
        $points += [
            'ClimateMode'        => ['name' => 'Climate mode', 'path' => 'climateControl.mode', 'type' => 'enumeration', 'options' => ['off' => 'Off', 'manual' => 'Manual', 'auto' => 'Auto', 'error' => 'Error'], 'icon' => 'temperature-half'],
            'ClimateStatus'      => ['name' => 'Climate control', 'path' => 'climateControl.status', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'temperature-half'],
            'BlowerSpeed'        => ['name' => 'Blower speed', 'path' => 'climateControl.speed', 'type' => 'enumeration', 'options' => [-1 => 'Auto', 0 => 'Off', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8'], 'icon' => 'fan'],
            'ClimateTemperature' => ['name' => 'Climate set temperature', 'path' => 'climateControl.temperature.value', 'type' => 'float', 'quantity' => 'temperature', 'unitPath' => 'climateControl.temperature.unit', 'digits' => 1, 'icon' => 'temperature-half'],
            'WindshieldDefrost'  => ['name' => 'Windshield defrost', 'path' => 'windshield.defrost', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'snowflake'],
            'SteeringWheelHeat'  => ['name' => 'Steering wheel heating', 'path' => 'steeringWheelHeat', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'fire'],
        ];
        foreach (self::positions() as $position => [$row, $side, $caption]) {
            $points['SeatHeat' . $position] = ['name' => 'Seat heating ' . $caption, 'path' => 'seats[' . $row . '].' . $side . '.heat', 'type' => 'enumeration', 'options' => self::SEAT_LEVEL, 'icon' => 'fire'];
        }
        foreach (self::positions() as $position => [$row, $side, $caption]) {
            $points['SeatVentilation' . $position] = ['name' => 'Seat ventilation ' . $caption, 'path' => 'seats[' . $row . '].' . $side . '.ventilation', 'type' => 'enumeration', 'options' => self::SEAT_LEVEL, 'icon' => 'fan'];
        }
        $points['OutsideTemperature'] = ['name' => 'Outside temperature', 'path' => 'outsideTemperature.value', 'type' => 'float', 'quantity' => 'temperature', 'unitPath' => 'outsideTemperature.unit', 'digits' => 1, 'icon' => 'temperature-half'];
        $points['WarningCount'] = ['name' => 'Number of warnings', 'derive' => ['warningCount'], 'type' => 'integer', 'min' => 0, 'icon' => 'triangle-exclamation'];
        foreach (self::WARNINGS as $code => $name) {
            $points['Warning' . ucfirst($code)] = ['name' => $name, 'derive' => ['warning', $code], 'type' => 'boolean', 'options' => self::NO_YES, 'icon' => 'triangle-exclamation'];
            if (in_array($code, self::COMBUSTION_WARNINGS, true)) {
                $points['Warning' . ucfirst($code)]['notFor'] = ['EV'];
            }
        }
        return self::group('Status', $points);
    }

    /** @return array<string, array<string, mixed>> */
    private static function location(): array
    {
        return self::group('Location', [
            'LocationReportTime' => self::reportTime('Location Report Time'),
            'Latitude'           => ['name' => 'Latitude', 'path' => 'location.latitude', 'type' => 'float', 'suffix' => '°', 'digits' => 6, 'min' => -90, 'max' => 90, 'icon' => 'location-dot'],
            'Longitude'          => ['name' => 'Longitude', 'path' => 'location.longitude', 'type' => 'float', 'suffix' => '°', 'digits' => 6, 'min' => -180, 'max' => 180, 'icon' => 'location-dot'],
            'Altitude'           => ['name' => 'Altitude', 'path' => 'location.altitude', 'type' => 'float', 'suffix' => ' m', 'digits' => 0, 'icon' => 'mountain'],
            'Heading'            => ['name' => 'Heading', 'path' => 'location.heading', 'type' => 'float', 'suffix' => '°', 'digits' => 0, 'min' => 0, 'max' => 359, 'icon' => 'compass'],
            'Speed'              => ['name' => 'Speed', 'path' => 'location.speed.value', 'type' => 'float', 'quantity' => 'speed', 'unitPath' => 'location.speed.unit', 'digits' => 0, 'icon' => 'gauge'],
            'CoordinateSystem'   => ['name' => 'Coordinate system', 'path' => 'location.type', 'type' => 'enumeration', 'options' => [0 => 'WGS84', 1 => 'Bessel', 2 => 'CoordShift'], 'icon' => 'globe'],
            'Map'                => ['name' => 'Map', 'path' => 'location', 'type' => 'map', 'default' => false, 'note' => 'The position is sent to OpenStreetMap whenever the map is displayed.', 'icon' => 'map-location-dot'],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function freezer(): array
    {
        return self::group('Freezer', [
            'FreezerReportTime'  => self::reportTime('Freezer Report Time'),
            'FreezerStatus'      => ['name' => 'Freezer', 'path' => 'freezer.status', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'snowflake'],
            'FreezerTemperature' => ['name' => 'Freezer temperature', 'path' => 'freezer.temperature', 'type' => 'float', 'quantity' => 'temperature', 'unit' => 'C', 'digits' => 1, 'icon' => 'temperature-low'],
        ]);
    }

    /** @return float|array{float, float}|null the conversion of a reported unit into the base unit */
    private static function reportedConversion(string $quantity, string $reportedUnit): float|array|null
    {
        foreach (self::QUANTITIES[$quantity]['reported'] as $reported => $conversion) {
            if (strcasecmp((string) $reported, $reportedUnit) === 0) {
                return $conversion;
            }
        }
        return null;
    }

    /** @param float|array{float, float} $conversion a factor, or [factor, offset] */
    private static function apply(float|array $conversion, float $value): float
    {
        return is_array($conversion) ? $value * $conversion[0] + $conversion[1] : $value * $conversion;
    }

    /** @return array<string, mixed> */
    private static function reportTime(string $name): array
    {
        return ['name' => $name, 'path' => 'timestamp', 'type' => 'datetime', 'timing' => true, 'icon' => 'clock'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function tripPowertrain(): array
    {
        return self::tripGroup('Trip Powertrain', 'TripPowertrain', 'powertrain', [
            'TripHighVoltageMin'    => ['name' => 'Minimum high-voltage battery voltage', 'path' => 'batteries.hv.voltage', 'aggregate' => 'min', 'type' => 'float', 'suffix' => ' V', 'digits' => 1, 'icon' => 'car-battery'],
            'TripHighVoltageMax'    => ['name' => 'Maximum high-voltage battery voltage', 'path' => 'batteries.hv.voltage', 'aggregate' => 'max', 'type' => 'float', 'suffix' => ' V', 'digits' => 1, 'icon' => 'car-battery'],
            'TripOilTemperatureMax' => ['name' => 'Maximum engine oil temperature', 'path' => 'engine.oilTemperature', 'aggregate' => 'max', 'notFor' => ['EV'], 'type' => 'float', 'quantity' => 'temperature', 'unit' => 'C', 'digits' => 1, 'icon' => 'oil-can'],
            'TripEngineSpeedMax'    => ['name' => 'Maximum engine speed', 'path' => 'engine.rpm', 'aggregate' => 'max', 'notFor' => ['EV'], 'type' => 'integer', 'suffix' => ' rpm', 'min' => 0, 'icon' => 'gauge-high'],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function tripSpeed(): array
    {
        $speed = ['path' => 'location.speed', 'type' => 'float', 'quantity' => 'speed', 'unitPath' => 'location.speedUnit', 'invalidFrom' => 255, 'digits' => 0, 'min' => 0, 'icon' => 'gauge-high'];
        $points = [
            'TripSpeedMax'     => ['name' => 'Maximum speed', 'aggregate' => 'max'] + $speed,
            'TripSpeedAverage' => ['name' => 'Average speed', 'aggregate' => 'average'] + $speed,
        ];
        foreach (self::positions() as $position => [, , $caption]) {
            $points['TripWheelSpeedMax' . $position] = ['name' => 'Maximum wheel speed ' . $caption, 'path' => 'wheel.speed' . $position, 'aggregate' => 'max', 'type' => 'float', 'quantity' => 'speed', 'unit' => 'kph', 'digits' => 0, 'min' => 0, 'icon' => 'gauge-high'];
        }
        return self::tripGroup('Trip Speed', 'TripSpeed', 'speed', $points);
    }

    /** @return array<string, array<string, mixed>> */
    private static function tripDriving(): array
    {
        $isla = ['none' => 'None', 'off' => 'Off', 'information' => 'Information', 'warning' => 'Warning', 'assist' => 'Assist'];
        $belt = ['unbelted' => 'Unbelted', 'belted' => 'Belted'];
        return self::tripGroup('Trip Driving', 'TripDriving', 'driving', [
            'TripDistance'             => ['name' => 'Trip distance', 'path' => 'odometer.value', 'aggregate' => 'delta', 'type' => 'float', 'quantity' => 'distance', 'unit' => 'km', 'digits' => 1, 'min' => 0, 'icon' => 'route'],
            'TripOdometerEnd'          => ['name' => 'Odometer at Trip end', 'path' => 'odometer.value', 'aggregate' => 'last', 'type' => 'float', 'quantity' => 'distance', 'unit' => 'km', 'digits' => 1, 'icon' => 'road'],
            'TripAccelerationMax'      => ['name' => 'Maximum acceleration', 'path' => 'acceleration.longitudinal', 'aggregate' => 'max', 'type' => 'float', 'suffix' => ' g', 'digits' => 2, 'icon' => 'gauge-high'],
            'TripDecelerationMax'      => ['name' => 'Maximum deceleration', 'path' => 'acceleration.longitudinal', 'aggregate' => 'max', 'scale' => -1.0, 'type' => 'float', 'suffix' => ' g', 'digits' => 2, 'icon' => 'gauge'],
            'TripYawRateMax'           => ['name' => 'Maximum yaw rate', 'path' => 'angularVelocity.yaw', 'aggregate' => 'maxAbs', 'type' => 'float', 'suffix' => ' °/s', 'digits' => 1, 'icon' => 'rotate'],
            'TripSteeringAngleMax'     => ['name' => 'Maximum steering angle', 'path' => 'steeringWheel.angle', 'aggregate' => 'maxAbs', 'type' => 'float', 'suffix' => '°', 'digits' => 0, 'icon' => 'arrows-left-right'],
            'TripSteeringSpeedMax'     => ['name' => 'Maximum steering speed', 'path' => 'steeringWheel.rotationSpeed', 'aggregate' => 'maxAbs', 'type' => 'float', 'suffix' => ' °/s', 'digits' => 0, 'icon' => 'arrows-left-right'],
            'TripIgnitionEnd'          => ['name' => 'Ignition at Trip end', 'path' => 'ignition.status', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'key'],
            'TripGearEnd'              => ['name' => 'Gear at Trip end', 'path' => 'transmission.gear', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => ['p' => 'Park', 'r' => 'Reverse', 'n' => 'Neutral', 'd' => 'Drive'], 'icon' => 'gears'],
            'TripSeatbeltDriverEnd'    => ['name' => 'Driver seat belt at Trip end', 'path' => 'seatbelt.driver', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => $belt, 'icon' => 'user'],
            'TripSeatbeltPassengerEnd' => ['name' => 'Front passenger seat belt at Trip end', 'path' => 'seatbelt.assistance', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => $belt, 'icon' => 'user'],
            'TripSpeedLimitAssistEnd'  => ['name' => 'Speed limit assist at Trip end', 'path' => 'isla.optionUsm', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => $isla, 'icon' => 'gauge'],
            'TripSpeedLimitAssist2End' => ['name' => 'Speed limit assist (option 2) at Trip end', 'path' => 'isla.optionUsm2', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => $isla, 'icon' => 'gauge'],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function tripStatus(): array
    {
        $points = [];
        $doors = ['FrontLeft' => 'openDriver', 'FrontRight' => 'openAssistance', 'RearLeft' => 'openRearLeft', 'RearRight' => 'openRearRight'];
        foreach (self::positions() as $position => [, , $caption]) {
            $points['TripDoor' . $position . 'End'] = ['name' => 'Door ' . $caption . ' at Trip end', 'path' => 'doors.' . $doors[$position], 'aggregate' => 'last', 'type' => 'enumeration', 'options' => ['close' => 'Closed', 'open' => 'Open'], 'icon' => 'door-open'];
        }
        foreach (self::positions() as $position => [, , $caption]) {
            $points['TripTirePressureMin' . $position] = ['name' => 'Minimum tire pressure ' . $caption, 'path' => 'tire.pressure' . $position, 'aggregate' => 'min', 'type' => 'float', 'quantity' => 'pressure', 'unitPath' => 'tire.pressureUnit', 'digits' => 1, 'icon' => 'gauge'];
        }
        $points += [
            'TripOutsideTemperatureAverage' => ['name' => 'Average outside temperature', 'path' => 'outsideTemperature.value', 'aggregate' => 'average', 'type' => 'float', 'quantity' => 'temperature', 'unit' => 'C', 'digits' => 1, 'icon' => 'temperature-half'],
            'TripLampEnd'                   => ['name' => 'Light switch at Trip end', 'path' => 'lamp.setting', 'aggregate' => 'last', 'type' => 'enumeration', 'options' => ['lightSwOff' => 'Off', 'tailSwOn' => 'Parking lights', 'headLampLowSwOn' => 'Low beam', 'autoLightSwOn' => 'Auto'], 'icon' => 'lightbulb'],
            'TripLowFuelWarningEnd'         => ['name' => 'Low fuel warning at Trip end', 'path' => 'warnings.lowFuelLevel', 'aggregate' => 'last', 'notFor' => ['EV'], 'type' => 'enumeration', 'options' => self::ON_OFF, 'icon' => 'gas-pump'],
        ];
        return self::tripGroup('Trip Status', 'TripStatus', 'status', $points);
    }

    /**
     * Adds the Data Points every Trip Data Group has: start and duration of the last Trip, and its raw JSON.
     *
     * @param string $prefix ident prefix
     * @param string $name lower-case name of the Data Group in the variable names
     * @param array<string, array<string, mixed>> $points
     * @return array<string, array<string, mixed>>
     */
    private static function tripGroup(string $group, string $prefix, string $name, array $points): array
    {
        return self::group($group, [
            $prefix . 'Start'    => ['name' => 'Trip start (' . $name . ')', 'path' => 'triggerStartTime', 'type' => 'datetime', 'timing' => true, 'icon' => 'clock'],
            $prefix . 'Duration' => ['name' => 'Trip duration (' . $name . ')', 'path' => 'tripLength', 'type' => 'duration', 'scale' => 0.001, 'timing' => true, 'icon' => 'hourglass'],
        ] + $points + [
            $prefix . 'JSON' => ['name' => 'Raw data of the last Trip (' . $name . ')', 'type' => 'json', 'default' => false, 'note' => 'Raw JSON for your own scripts, truncated if it exceeds 64 KiB.', 'icon' => 'code'],
        ]);
    }

    /** @return array<string, array{int, string, string}> position => [row index, side, caption]; row 0 is the front */
    private static function positions(): array
    {
        return [
            'FrontLeft'  => [0, 'left', 'front left'],
            'FrontRight' => [0, 'right', 'front right'],
            'RearLeft'   => [1, 'left', 'rear left'],
            'RearRight'  => [1, 'right', 'rear right'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $points
     * @return array<string, array<string, mixed>>
     */
    private static function group(string $group, array $points): array
    {
        return array_map(static fn (array $point): array => ['group' => $group] + $point, $points);
    }
}
