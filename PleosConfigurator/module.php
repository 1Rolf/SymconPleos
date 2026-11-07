<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/PleosAccountClient.php';

class PleosConfigurator extends IPSModuleStrict
{
    use PleosAccountClient;

    private const VEHICLE_MODULE_ID = '{B2E3CFBD-1954-0C92-02B1-F44BBDFA75A7}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $vehicles = $this->fetchVehicles();
        $existing = $this->existingVehicles();
        $values = [];
        foreach ($vehicles ?? [] as $vehicle) {
            $vin = $vehicle['vin'];
            $nickname = (string) ($vehicle['nickname'] ?? '');
            // The sales name ("EV2"), or the model code ("QV1") when the API sends none.
            $model = (string) ($vehicle['sellname'] ?? '');
            if ($model === '') {
                $model = (string) ($vehicle['name'] ?? '');
            }
            $name = $nickname !== '' ? $nickname : $model;
            $instanceID = $existing[$vin] ?? 0;
            // `create` also for an existing instance: Symcon shows a row with an instance but without `create` in red.
            $values[] = [
                'name'       => $instanceID !== 0 ? IPS_GetName($instanceID) : $name, // the console shows an instance's own name
                'model'      => $model,
                'vin'        => $vin,
                'instanceID' => $instanceID,
                'create'     => [
                    'moduleID'      => self::VEHICLE_MODULE_ID,
                    'configuration' => ['VIN' => $vin],
                    'name'          => $name,
                ],
            ];
            unset($existing[$vin]);
        }
        // Instances whose Vehicle the API Key no longer covers stay listed, without `create` and so in red, so the owner can
        // find and delete them.
        foreach ($existing as $vin => $instanceID) {
            $values[] = [
                'name'       => IPS_GetName($instanceID),
                'model'      => '',
                'vin'        => (string) $vin,
                'instanceID' => $instanceID,
            ];
        }
        foreach ($form['actions'] as &$element) {
            match ($element['name']) {
                'EmptyHint'   => $element['visible'] = $vehicles === [],
                'Unavailable' => $element['visible'] = $vehicles === null,
                'Vehicles'    => $element['values'] = $values,
                default       => null,
            };
        }
        return json_encode($form);
    }

    /**
     * Pleos Vehicle instances connected to the same Pleos Account as this Configurator.
     *
     * @return array<string, int> instance ID by VIN
     */
    private function existingVehicles(): array
    {
        $account = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $vehicles = [];
        if ($account === 0) {
            return $vehicles;
        }
        foreach (IPS_GetInstanceListByModuleID(self::VEHICLE_MODULE_ID) as $instanceID) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] === $account) {
                $vehicles[(string) IPS_GetProperty($instanceID, 'VIN')] = $instanceID;
            }
        }
        return $vehicles;
    }

    /**
     * Asks the Pleos Account for the Vehicles covered by the API Key.
     *
     * @return list<array<string, mixed>>|null null when the Account could not deliver the list
     */
    private function fetchVehicles(): ?array
    {
        if (!$this->HasActiveParent()) {
            return null;
        }
        $reply = $this->apiRequest('GET', '/vehicles/consent');
        $vehicles = $reply['Body']['data']['vehicles'] ?? null;
        if ($reply['Status'] !== 200 || !is_array($vehicles)) {
            return null;
        }
        return array_values(array_filter($vehicles, static fn ($vehicle): bool => is_array($vehicle) && is_string($vehicle['vin'] ?? null) && $vehicle['vin'] !== ''));
    }
}
