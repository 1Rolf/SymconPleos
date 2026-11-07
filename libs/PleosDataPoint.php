<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosDataPointCatalog.php';
include_once __DIR__ . '/PleosDataPointContext.php';
include_once __DIR__ . '/PleosDataPointType.php';

/**
 * One Data Point of the catalog, immutable: its flags, its variable and how it decodes its value from a Data Group's
 * data. What its type means lives in PleosDataPointType.
 */
final class PleosDataPoint
{
    /** @var array<string, self>|null */
    private static ?array $all = null;

    /**
     * @param list<string> $notFor Vehicle types the Data Point does not fit
     * @param list<mixed> $arguments of the derivation
     */
    private function __construct(
        public readonly string $ident,
        public readonly string $group,
        /** English, translated through the module's locale.json */
        public readonly string $name,
        /** English note for the selection form, translated through the module's locale.json */
        public readonly ?string $note,
        /** Kept out of the default selection and of the Availability Check's proposal (the map, the raw Trip JSON). */
        public readonly bool $offByDefault,
        /** A Report Time, Trip start or Trip duration: it tells when, not what. */
        public readonly bool $timing,
        private readonly array $notFor,
        private readonly ?string $path,
        private readonly ?string $derivation,
        private readonly array $arguments,
        private readonly ?string $unit,
        private readonly ?string $unitPath,
        private readonly ?string $aggregate,
        private readonly PleosDataPointType $type,
    ) {
    }

    /** @param array<string, mixed> $entry a Data Point of PleosDataPointCatalog::dataPoints() */
    public static function fromCatalog(string $ident, array $entry): self
    {
        return new self(
            $ident,
            $entry['group'],
            $entry['name'],
            $entry['note'] ?? null,
            ($entry['default'] ?? true) === false,
            ($entry['timing'] ?? false) === true,
            $entry['notFor'] ?? [],
            $entry['path'] ?? null,
            $entry['derive'][0] ?? null,
            array_slice($entry['derive'] ?? [], 1),
            $entry['unit'] ?? null,
            $entry['unitPath'] ?? null,
            $entry['aggregate'] ?? null,
            PleosDataPointType::fromCatalog($entry),
        );
    }

    /** @return array<string, self> every Data Point, keyed by ident in display order */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (PleosDataPointCatalog::dataPoints() as $ident => $entry) {
                self::$all[$ident] = self::fromCatalog($ident, $entry);
            }
        }
        return self::$all;
    }

    /** @return array<string, self> the Data Points of one Data Group, keyed by ident in display order */
    public static function ofGroup(string $group): array
    {
        return array_filter(self::all(), static fn (self $dataPoint): bool => $dataPoint->group === $group);
    }

    public static function find(string $ident): ?self
    {
        return self::all()[$ident] ?? null;
    }

    /** Whether the Data Point fits a Vehicle type (`type` of the vehicle list); the Availability Check proposes only those. */
    public function fitsVehicleType(string $vehicleType): bool
    {
        return !in_array($vehicleType, $this->notFor, true);
    }

    /** The VARIABLETYPE_* of the Data Point's variable. */
    public function variableType(): int
    {
        return $this->type->variableType();
    }

    /** @return array<string, mixed> the presentation of the Data Point's variable */
    public function presentation(PleosDataPointContext $context): array
    {
        return $this->type->presentation($context);
    }

    /** A decoded value as the selection list shows it, with its presentation's caption or suffix. */
    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return $this->type->displayValue($value, $context);
    }

    /** Whether a decoded value is a raw value the catalog has no caption for (an enumeration value it does not know). */
    public function lacksCaption(bool|int|float|string $value): bool
    {
        return $this->type->lacksCaption($value);
    }

    /**
     * The value of the Data Point's variable from a Data Group's data, or null if there is no valid value: missing,
     * `null`, `"invalid"` or not decodable. A Data Point of a Trip Data Group reads the last Trip.
     *
     * @param array<string, mixed> $data the response's `data`
     */
    public function decode(array $data, PleosDataPointContext $context): bool|int|float|string|null
    {
        if (PleosDataPointCatalog::isTripDataGroup($this->group)) {
            $data = PleosDataPointCatalog::lastTrip($data);
            if ($data === null) {
                return null;
            }
        }
        if ($this->aggregate !== null) {
            return $this->aggregate($data, $context);
        }
        [$found, $raw, $unit] = $this->read($data);
        return $found ? $this->decodeRaw($raw, $unit, $context) : null;
    }

    /**
     * Reads the raw value at the path or through the derivation; without either, the raw value is the whole data
     * (the raw Trip JSON reads the whole Trip).
     *
     * @param array<string, mixed> $data
     * @return array{bool, mixed, mixed} whether there is a value, the raw value and its reported unit (null if none)
     */
    private function read(array $data): array
    {
        if ($this->derivation !== null) {
            return PleosDataPointCatalog::derive($this->derivation, $this->arguments, $data);
        }
        if ($this->path === null) {
            return [true, $data, null];
        }
        [$found, $raw] = PleosDataPointCatalog::resolve($data, $this->path);
        $unit = $this->unit ?? ($this->unitPath === null ? null : PleosDataPointCatalog::resolve($data, $this->unitPath)[1]);
        return [$found, $raw, $unit];
    }

    private function decodeRaw(mixed $raw, mixed $unit, PleosDataPointContext $context): bool|int|float|string|null
    {
        return $raw === null || $raw === 'invalid' ? null : $this->type->decode($raw, $unit, $context);
    }

    /**
     * Reduces the valid samples of a Trip's sample array to one key figure, see PleosDataPointCatalog. Each sample is
     * decoded like a single value with the unit sample at its position.
     *
     * @param array<string, mixed> $trip
     */
    private function aggregate(array $trip, PleosDataPointContext $context): bool|int|float|string|null
    {
        [$found, $samples] = PleosDataPointCatalog::resolve($trip, (string) $this->path);
        if (!$found || !is_array($samples) || !array_is_list($samples)) {
            return null;
        }
        $units = $this->unitPath === null ? null : PleosDataPointCatalog::resolve($trip, $this->unitPath)[1];
        $values = [];
        foreach ($samples as $index => $raw) {
            $value = $this->decodeRaw($raw, $this->unit ?? (is_array($units) ? ($units[$index] ?? null) : $units), $context);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        if ($values === []) {
            return null;
        }
        return $this->type->aggregated(match ($this->aggregate) {
            'min'     => min($values),
            'max'     => max($values),
            'maxAbs'  => max(array_map('abs', $values)),
            'average' => array_sum($values) / count($values),
            'delta'   => end($values) - $values[0],
            'last'    => end($values),
        });
    }
}
