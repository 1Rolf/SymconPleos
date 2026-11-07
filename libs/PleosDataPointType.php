<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosApi.php';
include_once __DIR__ . '/PleosDataPointCatalog.php';
include_once __DIR__ . '/PleosDataPointContext.php';

/**
 * Everything the `type` of a Data Point means: how a raw value is decoded, the variable type, the presentation and
 * how the selection list shows a value. One class per type, built from the type's keys of a catalog entry (see
 * PleosDataPointCatalog); PleosDataPoint reads the raw value and hands it over.
 */
abstract class PleosDataPointType
{
    /** Catalog `type` => its class */
    private const TYPES = [
        'float'       => PleosFloatType::class,
        'integer'     => PleosIntegerType::class,
        'duration'    => PleosDurationType::class,
        'datetime'    => PleosDateTimeType::class,
        'boolean'     => PleosBooleanType::class,
        'enumeration' => PleosEnumerationType::class,
        'map'         => PleosMapType::class,
        'json'        => PleosJsonType::class,
    ];

    /** @param array<string, mixed> $entry a catalog entry */
    public static function fromCatalog(array $entry): self
    {
        $class = self::TYPES[$entry['type']] ?? throw new InvalidArgumentException('Unknown Data Point type ' . $entry['type']);
        return $class::fromEntry($entry);
    }

    /** @param array<string, mixed> $entry */
    abstract public static function fromEntry(array $entry): static;

    /** The VARIABLETYPE_* of the Data Point's variable. */
    abstract public function variableType(): int;

    /**
     * The presentation of the Data Point's variable. Symcon rejects parameters a presentation does not take, such as
     * ICON on date/time; the kernel's rules are in tests/fixtures/symcon/presentations.json (see
     * docs/research/symcon-sdk.md, "Presentations").
     *
     * @return array<string, mixed>
     */
    abstract public function presentation(PleosDataPointContext $context): array;

    /**
     * Turns a raw value (neither null nor "invalid") with its reported unit into the value of the variable, or
     * returns null if it is not a valid value.
     */
    abstract public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): bool|int|float|string|null;

    /** A decoded value as the selection list shows it. */
    abstract public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string;

    /** Whether the presentation shows a decoded value without a caption; only an enumeration has captions to miss. */
    public function lacksCaption(bool|int|float|string $value): bool
    {
        return false;
    }

    /** The key figure of a Trip aggregate (see PleosDataPoint) as the variable takes it. */
    public function aggregated(bool|int|float|string $value): bool|int|float|string
    {
        return $value;
    }

    /**
     * An option of the value presentation with a translated caption, without icon and color.
     *
     * @return array<string, mixed>
     */
    protected function option(bool|int|string $value, string $caption, PleosDataPointContext $context): array
    {
        return [
            'Value'       => $value,
            'Caption'     => is_numeric($caption) ? $caption : $context->translate($caption),
            'IconActive'  => false,
            'IconValue'   => '',
            'ColorActive' => false,
            'ColorValue'  => -1,
        ];
    }

    /**
     * The caption of the option a decoded value has, or the value itself if it has none.
     *
     * @param list<array<string, mixed>> $options see option()
     */
    protected static function caption(array $options, bool|int|float|string $value): string
    {
        foreach ($options as $option) {
            if ($option['Value'] === $value) {
                return $option['Caption'];
            }
        }
        return is_bool($value) ? json_encode($value) : (string) $value;
    }
}

/**
 * A number: parsed from the raw value, checked against `invalidFrom`, scaled and converted into the owner's unit of
 * its `quantity`.
 */
abstract class PleosNumberType extends PleosDataPointType
{
    final public function __construct(
        private readonly float $scale,
        private readonly int|float|null $invalidFrom,
        private readonly ?string $quantity,
        /** The boolean property that, when set, treats a reported `mile`/`miles` as km, or null */
        private readonly ?string $mileIsKmOption,
        private readonly string $suffix,
        private readonly ?int $digits,
        private readonly int|float|null $min,
        private readonly int|float|null $max,
        private readonly string $icon,
    ) {
    }

    public static function fromEntry(array $entry): static
    {
        return new static(
            (float) ($entry['scale'] ?? 1.0),
            $entry['invalidFrom'] ?? null,
            $entry['quantity'] ?? null,
            $entry['mileIsKmOption'] ?? null,
            $entry['suffix'] ?? '',
            $entry['digits'] ?? null,
            $entry['min'] ?? null,
            $entry['max'] ?? null,
            $entry['icon'] ?? '',
        );
    }

    public function presentation(PleosDataPointContext $context): array
    {
        $unit = $this->quantity === null ? [] : PleosDataPointCatalog::QUANTITIES[$this->quantity]['units'][$context->unit($this->quantity)];
        $presentation = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $this->icon, 'SUFFIX' => $unit['suffix'] ?? $this->suffix];
        if ($this->hasDigits()) {
            $presentation['DIGITS'] = $unit['digits'] ?? $this->digits ?? 1;
        }
        foreach (['MIN' => $this->min, 'MAX' => $this->max] as $parameter => $limit) {
            if ($limit !== null) {
                $presentation[$parameter] = $limit;
            }
        }
        return $presentation;
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        $presentation = $this->presentation($context);
        if (is_float($value)) {
            $value = number_format($value, (int) ($presentation['DIGITS'] ?? 1), '.', '');
        }
        return $value . $presentation['SUFFIX'];
    }

    /** Whether the presentation has DIGITS. */
    protected function hasDigits(): bool
    {
        return false;
    }

    /** The raw value as a number in the owner's unit, or null if there is no valid number. */
    protected function number(mixed $raw, mixed $unit, PleosDataPointContext $context): ?float
    {
        $number = PleosDataPointCatalog::number($raw);
        if ($number === null || ($this->invalidFrom !== null && $number >= $this->invalidFrom)) {
            return null;
        }
        $number *= $this->scale;
        if ($this->quantity === null) {
            return $number;
        }
        if ($this->mileIsKmOption !== null && in_array($unit, ['mile', 'miles'], true) && $context->mileIsKm($this->mileIsKmOption)) {
            $unit = 'km';
        }
        if (!is_string($unit) || !PleosDataPointCatalog::isReportedUnit($this->quantity, $unit)) {
            $context->debug('Decode', 'Unsupported ' . $this->quantity . ' unit ' . json_encode($unit));
            return null;
        }
        return PleosDataPointCatalog::convert($this->quantity, $number, $unit, $context->unit($this->quantity));
    }
}

final class PleosFloatType extends PleosNumberType
{
    public function variableType(): int
    {
        return VARIABLETYPE_FLOAT;
    }

    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): ?float
    {
        return $this->number($raw, $unit, $context);
    }

    protected function hasDigits(): bool
    {
        return true;
    }
}

/** An integer variable that holds a number rounded to a whole one; shared by the integer and the duration type. */
trait PleosWholeNumber
{
    public function variableType(): int
    {
        return VARIABLETYPE_INTEGER;
    }

    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): ?int
    {
        $number = $this->number($raw, $unit, $context);
        return $number === null ? null : (int) round($number);
    }
}

final class PleosIntegerType extends PleosNumberType
{
    use PleosWholeNumber;

    public function aggregated(bool|int|float|string $value): int
    {
        return (int) round((float) $value);
    }
}

/** A number of seconds. */
final class PleosDurationType extends PleosNumberType
{
    use PleosWholeNumber;

    public function presentation(PleosDataPointContext $context): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_DURATION];
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return sprintf('%d:%02d:%02d', intdiv((int) $value, 3600), intdiv((int) $value % 3600, 60), (int) $value % 60);
    }
}

/** Unix time from a Report Time or Trip start string. */
final class PleosDateTimeType extends PleosDataPointType
{
    public static function fromEntry(array $entry): static
    {
        return new self();
    }

    public function variableType(): int
    {
        return VARIABLETYPE_INTEGER;
    }

    public function presentation(PleosDataPointContext $context): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME];
    }

    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): ?int
    {
        return PleosDataPointCatalog::parseUtcTime($raw);
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return date('Y-m-d H:i:s', (int) $value);
    }
}

/** A JSON boolean, with a caption for false and one for true (`options`). */
final class PleosBooleanType extends PleosDataPointType
{
    /** @param array{string, string} $captions */
    public function __construct(private readonly array $captions, private readonly string $icon)
    {
    }

    public static function fromEntry(array $entry): static
    {
        return new self($entry['options'], $entry['icon'] ?? '');
    }

    public function variableType(): int
    {
        return VARIABLETYPE_BOOLEAN;
    }

    public function presentation(PleosDataPointContext $context): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode($this->options($context)), 'ICON' => $this->icon];
    }

    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): ?bool
    {
        return is_bool($raw) ? $raw : null;
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return self::caption($this->options($context), $value);
    }

    /** @return list<array<string, mixed>> */
    private function options(PleosDataPointContext $context): array
    {
        $options = [];
        foreach ([false, true] as $index => $value) {
            $options[] = $this->option($value, $this->captions[$index], $context);
        }
        return $options;
    }
}

/**
 * One of the raw values of `options`, stored as it is in the type the API sends it: a string variable for string raw
 * values, an integer variable for integer raw values (ADR 0003). A raw value missing from `options` is stored too; one
 * of another type is not a valid value.
 */
final class PleosEnumerationType extends PleosDataPointType
{
    /** @param array<int|string, string> $captions raw value => caption */
    public function __construct(private readonly array $captions, private readonly string $icon)
    {
    }

    public static function fromEntry(array $entry): static
    {
        return new self($entry['options'], $entry['icon'] ?? '');
    }

    public function variableType(): int
    {
        return is_int(array_key_first($this->captions)) ? VARIABLETYPE_INTEGER : VARIABLETYPE_STRING;
    }

    /** Captions on a read-only variable: see docs/research/symcon-sdk.md, "Presentations" */
    public function presentation(PleosDataPointContext $context): array
    {
        $presentation = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $this->icon];
        if ($this->variableType() === VARIABLETYPE_STRING) {
            return $presentation + ['OPTIONS' => json_encode($this->options($context))];
        }
        $intervals = [];
        foreach ($this->options($context) as ['Value' => $value, 'Caption' => $caption]) {
            $intervals[] = [
                'IntervalMinValue' => $value,
                'IntervalMaxValue' => $value,
                'ConstantActive'   => true,
                'ConstantValue'    => $caption,
                'ConversionFactor' => 1,
                'PrefixActive'     => false,
                'PrefixValue'      => '',
                'SuffixActive'     => false,
                'SuffixValue'      => '',
                'DigitsActive'     => false,
                'DigitsValue'      => 0,
                'IconActive'       => false,
                'IconValue'        => '',
                'ColorActive'      => false,
                'ColorValue'       => -1,
            ];
        }
        return $presentation + ['INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($intervals)];
    }

    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): int|string|null
    {
        if ($this->variableType() === VARIABLETYPE_STRING ? !is_string($raw) : !is_int($raw)) {
            $context->debug('Decode', 'Enumeration value of the wrong type ' . json_encode($raw));
            return null;
        }
        if ($this->lacksCaption($raw)) {
            $context->debug('Decode', 'Unknown enumeration value ' . json_encode($raw));
        }
        return $raw;
    }

    public function lacksCaption(bool|int|float|string $value): bool
    {
        return (is_int($value) || is_string($value)) && !array_key_exists($value, $this->captions);
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return self::caption($this->options($context), $value);
    }

    /** @return list<array<string, mixed>> */
    private function options(PleosDataPointContext $context): array
    {
        $options = [];
        foreach ($this->captions as $raw => $caption) {
            $options[] = $this->option($raw, $caption, $context);
        }
        return $options;
    }
}

/** HTML embedding OpenStreetMap at the WGS84 position of a `location` object. */
final class PleosMapType extends PleosDataPointType
{
    /** Half the side of the area the map shows around the position, in degrees. */
    private const SPAN = 0.005;

    public static function fromEntry(array $entry): static
    {
        return new self();
    }

    public function variableType(): int
    {
        return VARIABLETYPE_STRING;
    }

    public function presentation(PleosDataPointContext $context): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT, 'HTML_TYPE' => 0];
    }

    /** Returns null unless the `location` object holds WGS84 coordinates. */
    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): ?string
    {
        if (!is_array($raw) || ($raw['type'] ?? null) !== 0) {
            return null;
        }
        $latitude = $raw['latitude'] ?? null;
        $longitude = $raw['longitude'] ?? null;
        if ((!is_int($latitude) && !is_float($latitude)) || (!is_int($longitude) && !is_float($longitude)) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }
        $url = 'https://www.openstreetmap.org/export/embed.html?' . http_build_query([
            'bbox'   => sprintf('%F,%F,%F,%F', $longitude - self::SPAN, $latitude - self::SPAN, $longitude + self::SPAN, $latitude + self::SPAN),
            'layer'  => 'mapnik',
            'marker' => sprintf('%F,%F', $latitude, $longitude),
        ]);
        return '<iframe src="' . htmlspecialchars($url) . '" style="width: 100%; height: 100%; min-height: 300px; border: 0;" loading="lazy" referrerpolicy="no-referrer"></iframe>';
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return $context->translate('Position available');
    }
}

/** The raw JSON of the last Trip, truncated with a debug note if it exceeds the size a string variable handles well. */
final class PleosJsonType extends PleosDataPointType
{
    /** The raw JSON of a Trip is truncated beyond this many bytes. */
    private const LIMIT = 65536;

    /** @param string $group the Data Group, the debug sender of the truncation note */
    public function __construct(private readonly string $group, private readonly string $icon)
    {
    }

    public static function fromEntry(array $entry): static
    {
        return new self($entry['group'], $entry['icon'] ?? '');
    }

    public function variableType(): int
    {
        return VARIABLETYPE_STRING;
    }

    public function presentation(PleosDataPointContext $context): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $this->icon];
    }

    public function decode(mixed $raw, mixed $unit, PleosDataPointContext $context): string
    {
        $json = (string) json_encode($raw, PleosApi::RAW_JSON_FLAGS);
        if (strlen($json) <= self::LIMIT) {
            return $json;
        }
        $context->debug($this->group, sprintf('The raw JSON of the last Trip has %d bytes and was truncated to %d bytes', strlen($json), self::LIMIT));
        return mb_strcut($json, 0, self::LIMIT, 'UTF-8');
    }

    public function displayValue(bool|int|float|string $value, PleosDataPointContext $context): string
    {
        return $context->translate('Raw data available');
    }
}
