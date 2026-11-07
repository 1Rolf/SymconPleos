<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosDataPoint.php';
include_once __DIR__ . '/PleosDataPointCatalog.php';
include_once __DIR__ . '/PleosDataPointContext.php';

/**
 * The owner's Data Point selection of a Pleos Vehicle, read once from its stored properties, with the selection lists
 * of the form and the Availability Check's proposal.
 *
 * Stored as ADR 0002 describes: one string property per Data Group, `Selection<Group>` with the Data Group's name
 * without spaces, holding the rows `[{"Ident": …, "Selected": …}, …]`. A Data Point missing from the stored rows
 * counts as not selected.
 */
final class PleosDataPointSelection
{
    /**
     * @param array<string, array<string, bool>> $stored Data Group => ident => selected, as stored
     */
    private function __construct(private readonly array $stored)
    {
    }

    /**
     * Reads the stored selection of every Data Group.
     *
     * @param Closure(string): string $readProperty the instance's ReadPropertyString()
     */
    public static function read(Closure $readProperty): self
    {
        $stored = [];
        foreach (array_keys(PleosDataPointCatalog::allDataGroups()) as $group) {
            $stored[$group] = [];
            $rows = json_decode($readProperty(self::property($group)), true);
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && is_string($row['Ident'] ?? null) && !isset($stored[$group][$row['Ident']])) {
                    $stored[$group][$row['Ident']] = ($row['Selected'] ?? false) === true;
                }
            }
        }
        return new self($stored);
    }

    /**
     * The properties that hold the selection, with the default selection for a new Vehicle.
     *
     * @return array<string, string> property => stored rows
     */
    public static function defaultProperties(): array
    {
        $default = PleosDataPointCatalog::defaultSelection();
        $properties = [];
        foreach (array_keys(PleosDataPointCatalog::allDataGroups()) as $group) {
            $rows = [];
            foreach (array_keys(PleosDataPoint::ofGroup($group)) as $ident) {
                $rows[] = ['Ident' => $ident, 'Selected' => in_array($ident, $default, true)];
            }
            $properties[self::property($group)] = json_encode($rows);
        }
        return $properties;
    }

    /** The property, and form List, that holds a Data Group's selection: `SelectionTripPowertrain` for "Trip Powertrain". */
    public static function property(string $group): string
    {
        return 'Selection' . str_replace(' ', '', $group);
    }

    public function isSelected(string $ident): bool
    {
        $group = PleosDataPoint::find($ident)?->group;
        return $group !== null && ($this->stored[$group][$ident] ?? false);
    }

    /** @return array<string, PleosDataPoint> the selected Data Points of one Data Group, keyed by ident in display order */
    public function ofGroup(string $group): array
    {
        return array_filter(PleosDataPoint::ofGroup($group), fn (PleosDataPoint $dataPoint): bool => $this->isSelected($dataPoint->ident));
    }

    /** @return array<string, array<string, PleosDataPoint>> the Data Groups with a selected Data Point and their selected Data Points */
    public function byGroup(): array
    {
        $groups = [];
        foreach (array_keys(PleosDataPointCatalog::allDataGroups()) as $group) {
            $dataPoints = $this->ofGroup($group);
            if ($dataPoints !== []) {
                $groups[$group] = $dataPoints;
            }
        }
        return $groups;
    }

    /**
     * The selection list of one Data Group. Its rows come from the catalog, not from the stored property
     * (`loadValuesFromConfiguration` false), so new Data Points appear after a module update.
     *
     * @param Closure(string): bool $hasVariable whether the instance has a variable with this ident
     * @return array<string, mixed>
     */
    public function formList(string $group, PleosDataPointContext $context, Closure $hasVariable): array
    {
        $values = $this->rows($group, $context, $hasVariable);
        return [
            'type'                        => 'List',
            'name'                        => self::property($group),
            'caption'                     => 'Data Points',
            'rowCount'                    => count($values),
            'add'                         => false,
            'delete'                      => false,
            'loadValuesFromConfiguration' => false,
            'columns'                     => [
                ['name' => 'Ident', 'caption' => 'Ident', 'width' => '0px', 'visible' => false, 'save' => true],
                ['name' => 'Selected', 'caption' => 'Selected', 'width' => '90px', 'edit' => ['type' => 'CheckBox']],
                ['name' => 'Name', 'caption' => 'Data Point', 'width' => '300px'],
                ['name' => 'Value', 'caption' => 'Latest value', 'width' => '200px'],
                ['name' => 'Note', 'caption' => 'Note', 'width' => 'auto'],
            ],
            'values' => $values,
        ];
    }

    /**
     * The rows of one Data Group's selection list.
     *
     * @param Closure(string): bool $hasVariable whether the instance has a variable with this ident
     * @param array<string, bool|int|float|string> $latestValues ident => latest value, from the Availability Check
     * @param list<string>|null $proposal idents to show selected instead of the stored selection
     * @return list<array<string, mixed>>
     */
    public function rows(string $group, PleosDataPointContext $context, Closure $hasVariable, array $latestValues = [], ?array $proposal = null): array
    {
        $rows = [];
        foreach (PleosDataPoint::ofGroup($group) as $ident => $dataPoint) {
            $selected = $this->isSelected($ident);
            $notes = [];
            if (!$selected && $hasVariable($ident)) {
                $notes[] = $context->translate('Variable kept, no longer updated.');
            }
            if ($dataPoint->note !== null) {
                $notes[] = $context->translate($dataPoint->note);
            }
            $rows[] = [
                'Ident'    => $ident,
                'Selected' => $proposal === null ? $selected : in_array($ident, $proposal, true),
                'Name'     => $context->translate($dataPoint->name),
                'Value'    => isset($latestValues[$ident]) ? $dataPoint->displayValue($latestValues[$ident], $context) : '',
                'Note'     => implode(' ', $notes),
            ];
        }
        return $rows;
    }

    /**
     * The latest value of each Data Point of a Data Group that has a valid one.
     *
     * @param array<string, mixed>|null $data the response's `data`, null if the Data Group delivered none
     * @return array<string, bool|int|float|string> ident => value
     */
    public static function latestValues(string $group, ?array $data, PleosDataPointContext $context): array
    {
        $values = [];
        foreach (PleosDataPoint::ofGroup($group) as $ident => $dataPoint) {
            $value = $data === null ? null : $dataPoint->decode($data, $context);
            if ($value !== null) {
                $values[$ident] = $value;
            }
        }
        return $values;
    }

    /**
     * The Availability Check's proposal for one Data Group: the Data Points with a valid value that fit the Vehicle type.
     * A Data Point off by default keeps the owner's choice.
     *
     * @param array<string, bool|int|float|string> $latestValues ident => latest value, see latestValues()
     * @return list<string> the idents to propose
     */
    public function proposal(string $group, array $latestValues, string $vehicleType): array
    {
        $points = PleosDataPoint::ofGroup($group);
        $proposal = [];
        foreach ($points as $ident => $dataPoint) {
            if ($dataPoint->offByDefault) {
                // Off by default for a reason (the map sends the position to OpenStreetMap): the owner's choice stays.
                if ($this->isSelected($ident)) {
                    $proposal[] = $ident;
                }
            } elseif (isset($latestValues[$ident]) && $dataPoint->fitsVehicleType($vehicleType)) {
                $proposal[] = $ident;
            }
        }
        // A Report Time or Trip start alone tells nothing: the EV2 sends a freezer timestamp without any freezer values.
        $values = array_filter($proposal, static fn (string $ident): bool => !$points[$ident]->timing && !$points[$ident]->offByDefault);
        if ($values === []) {
            $proposal = array_values(array_filter($proposal, static fn (string $ident): bool => !$points[$ident]->timing));
        }
        return $proposal;
    }

    /**
     * The Data Points of a Data Group whose value is news to the owner: unselected Data Points with a valid value the
     * owner has seen neither in an Availability Check nor through a variable from an earlier selection. Data Points off
     * by default (the map) are a deliberate choice, not news.
     *
     * @param array<string, mixed> $data the response's `data`
     * @param list<string> $known idents the owner has already seen
     * @param Closure(string): bool $hasVariable whether the instance has a variable with this ident
     * @return array<string, PleosDataPoint> ident => Data Point
     */
    public function newData(string $group, array $data, PleosDataPointContext $context, array $known, Closure $hasVariable): array
    {
        $new = [];
        foreach (PleosDataPoint::ofGroup($group) as $ident => $dataPoint) {
            if ($dataPoint->offByDefault || in_array($ident, $known, true) || $this->isSelected($ident) || $hasVariable($ident) || $dataPoint->decode($data, $context) === null) {
                continue;
            }
            $new[$ident] = $dataPoint;
        }
        return $new;
    }
}
