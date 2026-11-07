<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/PleosApi.php';
include_once __DIR__ . '/../libs/PleosDataPointCatalog.php';
include_once __DIR__ . '/../libs/PleosDataPoint.php';
include_once __DIR__ . '/../libs/PleosDataPointSelection.php';
include_once __DIR__ . '/../libs/PleosClock.php';
include_once __DIR__ . '/../libs/PleosFormIO.php';
include_once __DIR__ . '/../libs/PleosPrivacy.php';
include_once __DIR__ . '/../libs/PleosAccountClient.php';
include_once __DIR__ . '/../libs/PleosDataGroupRequest.php';
include_once __DIR__ . '/../libs/PleosVehicleStatus.php';

class PleosVehicle extends IPSModuleStrict
{
    use PleosAccountClient;
    use PleosClock;
    use PleosDataGroupRequest;
    use PleosFormIO;

    /** Range Data Points the tile shows, in order of preference: the first one with a value is shown. */
    private const TILE_RANGE_IDENTS = ['RangeEV', 'RangePHEVElectric', 'RangePHEVTotal', 'RangeFCEV', 'RangeHEV', 'RangeICE'];

    /** Variables the tile shows. */
    private const TILE_IDENTS = ['StateOfCharge', 'Odometer', 'BatteryReportTime', 'PlugStatus', 'Charging', ...self::TILE_RANGE_IDENTS];

    /** JSON embedded in the tile's HTML must not close its script element. */
    private const TILE_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        // The Data Point selection: one property per Data Group (ADR 0002).
        foreach (PleosDataPointSelection::defaultProperties() as $property => $rows) {
            $this->RegisterPropertyString($property, $rows);
        }
        foreach (PleosDataPointCatalog::QUANTITIES as $quantity => $definition) {
            $this->RegisterPropertyString($definition['property'], PleosDataPointCatalog::unit($quantity, ''));
        }
        $this->RegisterPropertyBoolean('OdometerMileIsKm', false);

        // What the owner has been told, so the one-time messages are not repeated after a restart.
        $this->RegisterAttributeString('KnownDataPoints', '[]');
        $this->RegisterAttributeString('LoggedUnknownFields', '[]');
        $this->RegisterAttributeString('LoggedUncaptionedValues', '[]');
        $this->registerDataGroupRequestAttributes();

        $this->RegisterTimer('Update', 0, 'PLEOS_Update($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        // A new or removed Pleos Account changes the status at once, see followAccount().
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Variables are created only here, for the selected Data Points, and never removed (ADR 0002).
        $context = $this->dataPointContext();
        $selection = $this->selection();
        $position = 0;
        foreach (PleosDataPoint::all() as $ident => $dataPoint) {
            $position++;
            if ($selection->isSelected($ident)) {
                $this->MaintainVariable($ident, $this->Translate($dataPoint->name), $dataPoint->variableType(), $dataPoint->presentation($context), $position, true);
            }
        }

        $this->followTileVariables($selection);
        $this->UpdateVisualizationValue($this->tileStateJson());
        $this->followAccount();

        $vinSet = $this->hasVin();
        // Polling goes on whatever the Account's status, so the Account can recover through polling.
        $this->SetTimerInterval('Update', $vinSet ? max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000 : 0);
        $this->SetStatus(PleosVehicleStatus::whenApplied($vinSet, $this->accountStatus()));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!$this->hasVin()) {
            array_unshift($form['elements'], ['type' => 'Label', 'caption' => 'No VIN entered. Create this vehicle through a Pleos Configurator, which presets the VIN, or enter the VIN by hand.']);
        }
        $form['elements'][] = ['type' => 'Label', 'caption' => 'Variables are created only for the Data Points selected here, when you apply.'];
        $form['elements'][] = ['type' => 'Label', 'caption' => 'Deselected Data Points keep their variables: they are no longer updated and not deleted automatically. Delete them manually if you no longer need them.'];
        $selection = $this->selection();
        $context = $this->dataPointContext();
        foreach (array_keys(PleosDataPointCatalog::allDataGroups()) as $group) {
            $form['elements'][] = [
                'type'    => 'ExpansionPanel',
                'caption' => $group,
                'items'   => [$selection->formList($group, $context, $this->hasVariable(...))],
            ];
        }
        return json_encode($form);
    }

    /**
     * Requests the latest Vehicle Report through the Pleos Account and writes it into the variables.
     */
    public function Update(): void
    {
        if (!$this->hasVin()) {
            return;
        }
        $account = $this->accountID();
        if ($account === 0) {
            $this->SendDebug('Update', 'No Pleos Account connected', 0);
            $this->applyStatus(PleosVehicleStatus::afterPoll(null, [], $this->GetStatus()));
            return;
        }
        // Requests are sent even through an inactive Account, so the Account can recover through polling.
        $context = $this->dataPointContext();
        $selection = $this->selection();
        $selected = $selection->byGroup();
        $results = [];
        foreach ($this->pollDataGroups(array_keys($selected)) as $group => $result) {
            $results[] = $result;
            if ($result->notAsked()) {
                break;
            }
            if ($result->delivered()) {
                $this->logUncaptionedValues($group, $this->writeAndReturnValues($selected[$group], $result->data, $context));
                $this->logNewData($selection->newData($group, $result->data, $context, $this->knownDataPoints(), $this->hasVariable(...)), $group);
                $this->logUnknownFields($group, $result->data);
            }
        }
        $this->applyStatus(PleosVehicleStatus::afterPoll($this->accountStatus(), $results, $this->GetStatus()));
    }

    /**
     * Requests every Data Group once, shows each Data Point's latest value and the result per Data Group, and proposes
     * selecting the Data Points with a valid value. The proposal is only shown in the form; the owner applies it.
     */
    public function RunAvailabilityCheck(): void
    {
        if (!$this->hasVin() || $this->accountID() === 0) {
            return;
        }
        $type = $this->vehicleType();
        $context = $this->dataPointContext();
        $selection = $this->selection();
        $results = [];
        foreach (array_keys(PleosDataPointCatalog::allDataGroups()) as $group) {
            $result = $this->checkDataGroup($group);
            $reportTime = $result->reportTime();
            $results[] = [
                'DataGroup'  => $this->Translate($group),
                'Result'     => $this->availabilityResult($result),
                'ReportTime' => $reportTime === null ? '' : date('Y-m-d H:i:s', $reportTime),
            ];
            if ($result->delivered()) {
                $this->logUnknownFields($group, $result->data);
            }
            $latestValues = PleosDataPointSelection::latestValues($group, $result->data, $context);
            $this->logUncaptionedValues($group, $latestValues);
            // The owner sees these values now, so the new-data message does not report them as new.
            $this->remember('KnownDataPoints', array_keys($latestValues));
            $rows = $selection->rows($group, $context, $this->hasVariable(...), $latestValues, $selection->proposal($group, $latestValues, $type));
            $this->sendFormUpdate(PleosDataPointSelection::property($group), 'values', json_encode($rows));
        }
        $this->sendFormUpdate('AvailabilityResult', 'values', json_encode($results));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message === FM_CONNECT || $Message === FM_DISCONNECT) {
            $this->followAccount();
            $this->showAccountChange();
        } elseif ($Message === IM_CHANGESTATUS && $SenderID === $this->accountID()) {
            $this->showAccountChange();
        } elseif ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->tileStateJson());
        }
    }

    /**
     * Returns the HTML of the tile in the tile visualization.
     */
    public function GetVisualizationTile(): string
    {
        // The tile script translates its texts itself: server vs. viewer language, see docs/research/symcon-sdk.md.
        return strtr((string) file_get_contents(__DIR__ . '/module.html'), [
            '{{STATE}}' => $this->tileStateJson(),
        ]);
    }

    /**
     * Returns the raw responses of the vehicle list and all Data Groups as JSON, with VIN, nickname and position anonymized,
     * in the format that FakePleosApi::loadDiagnostics() serves as fixtures.
     */
    public function ExportDiagnostics(): string
    {
        $vin = $this->ReadPropertyString('VIN');
        $raw = [['Path' => '/vehicles/consent', 'Query' => []] + $this->apiRequest('GET', '/vehicles/consent')];
        $this->SendDebug('Diagnostics', 'GET /vehicles/consent → HTTP ' . $raw[0]['Status'], 0);
        foreach (array_keys(PleosDataPointCatalog::allDataGroups()) as $group) {
            $exchange = $this->exportDataGroup($group);
            $this->SendDebug('Diagnostics', PleosPrivacy::shortenVins('GET ' . $exchange['Path'] . ' → HTTP ' . $exchange['Status']), 0);
            $raw[] = $exchange;
        }

        $responses = [];
        foreach (PleosPrivacy::anonymizeDiagnostics($vin, $raw[0]['Body'], $raw) as $exchange) {
            $responses[] = [
                'method' => 'GET',
                'path'   => $exchange['Path'],
                'query'  => (object) $exchange['Query'],
                'status' => $exchange['Status'],
                'body'   => $exchange['Body'],
            ];
        }
        return json_encode(['responses' => $responses], JSON_PRETTY_PRINT | PleosApi::RAW_JSON_FLAGS);
    }

    /**
     * Follows the status of the connected Pleos Account (IM_CHANGESTATUS), so the Vehicle shows it without waiting for a poll.
     */
    private function followAccount(): void
    {
        $account = $this->accountID();
        foreach ($this->GetMessageList() as $sender => $messages) {
            if ($sender !== $account && in_array(IM_CHANGESTATUS, $messages, true)) {
                $this->UnregisterMessage($sender, IM_CHANGESTATUS);
            }
        }
        if ($account !== 0) {
            $this->RegisterMessage($account, IM_CHANGESTATUS);
        }
    }

    /**
     * The connected Pleos Account's instance status, or null when none is connected.
     */
    private function accountStatus(): ?int
    {
        $account = $this->accountID();
        return $account === 0 ? null : IPS_GetInstance($account)['InstanceStatus'];
    }

    /**
     * Shows the Account's status after it changed or another Account was connected.
     */
    private function showAccountChange(): void
    {
        $this->applyStatus(PleosVehicleStatus::whenAccountChanged($this->hasVin(), $this->accountStatus(), $this->GetStatus()));
    }

    /**
     * Whether the owner or the Pleos Configurator has entered a VIN; without one the Vehicle is not configured yet.
     */
    private function hasVin(): bool
    {
        return $this->ReadPropertyString('VIN') !== '';
    }

    /**
     * Applies a decision of PleosVehicleStatus; null leaves the status unchanged.
     */
    private function applyStatus(?int $status): void
    {
        if ($status !== null) {
            $this->SetStatus($status);
        }
    }

    /** The result of one Data Group as the Availability Check shows it. */
    private function availabilityResult(PleosDataGroupResult $result): string
    {
        return match (true) {
            $result->delivered()   => $this->Translate('Delivered'),
            $result->noDataYet()   => $this->Translate('No data yet'),
            $result->unreachable() => $this->Translate('Failed (no answer from the API)'),
            default                => sprintf($this->Translate('Failed (error %s)'), $result->code),
        };
    }

    /**
     * Logs once per Data Point that is news to the owner, see PleosDataPointSelection::newData(), and remembers it.
     *
     * @param array<string, PleosDataPoint> $new ident => Data Point
     */
    private function logNewData(array $new, string $group): void
    {
        foreach ($new as $dataPoint) {
            $this->LogMessage(sprintf($this->Translate('New data available for %s (%s). Repeat the Availability Check to select it.'), $this->Translate($dataPoint->name), $this->Translate($group)), KL_NOTIFY);
        }
        $this->remember('KnownDataPoints', array_keys($new));
    }

    /** @return list<string> the Data Points the owner has seen, which the new-data message no longer reports */
    private function knownDataPoints(): array
    {
        return json_decode($this->ReadAttributeString('KnownDataPoints'), true);
    }

    /**
     * Logs once per field path when a Data Group delivers a field no Data Point reads.
     *
     * @param array<string, mixed> $data the response's `data`
     */
    private function logUnknownFields(string $group, array $data): void
    {
        foreach (PleosDataPointCatalog::unknownFields($group, $data) as $path) {
            $this->logOnce('LoggedUnknownFields', $group . ' ' . $path, sprintf($this->Translate('The API delivers the unknown field %s in Data Group %s. Please report it so a module update can support it.'), $path, $this->Translate($group)));
        }
    }

    /**
     * Logs once per Data Point and raw value when an enumeration Data Point receives a value without a caption.
     *
     * @param array<string, bool|int|float|string> $values ident => decoded value of the Data Group's Data Points
     */
    private function logUncaptionedValues(string $group, array $values): void
    {
        foreach ($values as $ident => $value) {
            $dataPoint = PleosDataPoint::find($ident);
            if ($dataPoint !== null && $dataPoint->lacksCaption($value)) {
                $this->logOnce('LoggedUncaptionedValues', $ident . ' ' . json_encode($value), sprintf($this->Translate('The API delivers the value %s for %s (%s), which has no caption. Please report it so a module update can support it.'), (string) $value, $this->Translate($dataPoint->name), $this->Translate($group)));
            }
        }
    }

    /** Logs a message unless the list attribute already holds its key, and remembers the key across restarts. */
    private function logOnce(string $attribute, string $key, string $message): void
    {
        if (in_array($key, json_decode($this->ReadAttributeString($attribute), true), true)) {
            return;
        }
        $this->LogMessage($message, KL_NOTIFY);
        $this->remember($attribute, [$key]);
    }

    /**
     * Adds entries to a list attribute.
     *
     * @param list<string> $entries
     */
    private function remember(string $attribute, array $entries): void
    {
        $list = json_decode($this->ReadAttributeString($attribute), true);
        $merged = array_values(array_unique(array_merge($list, $entries)));
        if ($merged !== $list) {
            $this->WriteAttributeString($attribute, json_encode($merged));
        }
    }

    /** The Vehicle's `type` from the vehicle list (`EV`, `GN`, …), or '' if it is not listed. */
    private function vehicleType(): string
    {
        $vin = $this->ReadPropertyString('VIN');
        $vehicles = $this->apiRequest('GET', '/vehicles/consent')['Body']['data']['vehicles'] ?? [];
        foreach (is_array($vehicles) ? $vehicles : [] as $vehicle) {
            if (is_array($vehicle) && ($vehicle['vin'] ?? null) === $vin && is_string($vehicle['type'] ?? null)) {
                return $vehicle['type'];
            }
        }
        return '';
    }

    /**
     * Registers for updates of the variables the tile shows, so the tile is updated whenever one of its values changes.
     */
    private function followTileVariables(PleosDataPointSelection $selection): void
    {
        foreach ($this->GetMessageList() as $sender => $messages) {
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
        }
        foreach (self::TILE_IDENTS as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false && $selection->isSelected($ident)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    private function tileStateJson(): string
    {
        return json_encode($this->tileState(), self::TILE_JSON_FLAGS);
    }

    /**
     * The values the tile shows, read from the instance's variables. A value is null when its Data Point is not selected
     * or has no value yet.
     *
     * @return array<string, mixed>
     */
    private function tileState(): array
    {
        $selection = $this->selection();
        return [
            'stateOfCharge' => $this->tileNumber($selection, 'StateOfCharge'),
            'range'         => $this->tileRange($selection),
            'odometer'      => $this->tileNumber($selection, 'Odometer'),
            'reportTime'    => $this->tileValue($selection, 'BatteryReportTime'),
            'plugConnected' => $this->tilePlugConnected($selection),
            'charging'      => $this->tileValue($selection, 'Charging'),
        ];
    }

    /** Whether the charging cable is connected, from the plug status Data Point. */
    private function tilePlugConnected(PleosDataPointSelection $selection): ?bool
    {
        $connected = $this->tileValue($selection, 'PlugStatus');
        return $connected === null ? null : (bool) $connected;
    }

    /** @return array{value: float|int, digits: int, suffix: string}|null the first range Data Point with a value */
    private function tileRange(PleosDataPointSelection $selection): ?array
    {
        foreach (self::TILE_RANGE_IDENTS as $ident) {
            $range = $this->tileNumber($selection, $ident);
            if ($range !== null) {
                return $range;
            }
        }
        return null;
    }

    /** @return array{value: float|int, digits: int, suffix: string}|null a number with the presentation of its variable */
    private function tileNumber(PleosDataPointSelection $selection, string $ident): ?array
    {
        $value = $this->tileValue($selection, $ident);
        if ($value === null) {
            return null;
        }
        $presentation = IPS_GetVariablePresentation($this->GetIDForIdent($ident));
        return ['value' => $value, 'digits' => (int) ($presentation['DIGITS'] ?? 0), 'suffix' => (string) ($presentation['SUFFIX'] ?? '')];
    }

    private function tileValue(PleosDataPointSelection $selection, string $ident): mixed
    {
        if (!$selection->isSelected($ident)) {
            return null;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            return null;
        }
        return GetValue($id);
    }

    /**
     * Writes the selected Data Points of one Data Group. Values that are missing, `null`, `"invalid"` or otherwise not
     * decodable keep the variable's last valid value.
     *
     * @param array<string, PleosDataPoint> $dataPoints ident => Data Point
     * @param array<string, mixed> $data the response's `data`
     * @return array<string, bool|int|float|string> ident => value written
     */
    private function writeAndReturnValues(array $dataPoints, array $data, PleosDataPointContext $context): array
    {
        $written = [];
        foreach ($dataPoints as $ident => $dataPoint) {
            $value = $dataPoint->decode($data, $context);
            // A Data Point selected but not yet applied has no variable, and polling never creates one (ADR 0002).
            if ($value !== null && $this->hasVariable($ident)) {
                $this->SetValue($ident, $value);
                $written[$ident] = $value;
            }
        }
        return $written;
    }

    /** What the Data Points need from this instance: the owner's units and mile-as-km options, translation and debug output. */
    private function dataPointContext(): PleosDataPointContext
    {
        $units = [];
        foreach (PleosDataPointCatalog::QUANTITIES as $quantity => $definition) {
            $units[$quantity] = $this->ReadPropertyString($definition['property']);
        }
        $mileIsKm = [];
        foreach (PleosDataPointCatalog::mileIsKmOptions() as $option) {
            $mileIsKm[$option] = $this->ReadPropertyBoolean($option);
        }
        return new PleosDataPointContext(
            $units,
            $mileIsKm,
            $this->Translate(...),
            fn (string $sender, string $text): bool => $this->SendDebug($sender, $text, 0),
        );
    }

    /** The owner's Data Point selection, read once for one use. */
    private function selection(): PleosDataPointSelection
    {
        return PleosDataPointSelection::read($this->ReadPropertyString(...));
    }

    private function hasVariable(string $ident): bool
    {
        return @$this->GetIDForIdent($ident) !== false;
    }

    private function accountID(): int
    {
        return IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }
}
