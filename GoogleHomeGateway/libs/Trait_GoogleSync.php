<?php

declare(strict_types=1);

/**
 * GoogleSync_Trait — Verarbeitet den SYNC Intent von Google Home.
 *
 * Baut die vollständige Geräteliste (SYNC-Response) aus der Devices-Konfiguration auf.
 * Meldet nur Traits an Google, für die auch eine Variable konfiguriert ist.
 */
if (!trait_exists('GoogleSync_Trait')) {
    trait GoogleSync_Trait
    {
        protected function HandleSync(string $requestId): array
        {
            $devices     = $this->GetDevices();
            $googleDevs  = [];

            foreach ($devices as $device) {
                $googleDevs[] = $this->BuildSyncDevice($device);
            }

            $this->SetValue('LastSync', date('d.m.Y H:i:s'));
            $this->SetValue('ConnectedDevices', count($googleDevs));
            $this->SetValue('GatewayStatus', 1);

            $this->SLogInfo('SYNC: ' . count($googleDevs) . ' Geräte gemeldet');

            return [
                'requestId' => $requestId,
                'payload'   => [
                    'agentUserId' => $this->GetAgentUserId(),
                    'devices'     => $googleDevs,
                ],
            ];
        }

        private function BuildSyncDevice(array $device): array
        {
            $type   = (int)($device['type'] ?? 0);
            $traits = [];
            $attrs  = [];

            // Traits basierend auf Typ und konfigurierten Variablen
            switch ($type) {
                case GoogleHomeGateway::TYPE_SWITCH:
                case GoogleHomeGateway::TYPE_OUTLET:
                    $traits[] = 'action.devices.traits.OnOff';
                    break;

                case GoogleHomeGateway::TYPE_LIGHT_ONOFF:
                    $traits[] = 'action.devices.traits.OnOff';
                    break;

                case GoogleHomeGateway::TYPE_LIGHT_DIM:
                    $traits[] = 'action.devices.traits.OnOff';
                    if (!empty($device['Brightness_VarID'])) {
                        $traits[] = 'action.devices.traits.Brightness';
                    }
                    break;

                case GoogleHomeGateway::TYPE_LIGHT_COLOR:
                    $traits[] = 'action.devices.traits.OnOff';
                    if (!empty($device['Brightness_VarID'])) {
                        $traits[] = 'action.devices.traits.Brightness';
                    }
                    // ColorSetting nur wenn RGB oder ColorTemp konfiguriert
                    $hasRgb  = !empty($device['ColorRGB_VarID']);
                    $hasCct  = !empty($device['ColorTemp_VarID']);
                    if ($hasRgb || $hasCct) {
                        $traits[] = 'action.devices.traits.ColorSetting';
                        if ($hasRgb && $hasCct) {
                            $attrs['colorModel'] = 'rgb';
                            $attrs['colorTemperatureRange'] = [
                                'temperatureMinK' => 2000,
                                'temperatureMaxK' => 6500,
                            ];
                        } elseif ($hasRgb) {
                            $attrs['colorModel'] = 'rgb';
                        } elseif ($hasCct) {
                            $attrs['colorTemperatureRange'] = [
                                'temperatureMinK' => 2000,
                                'temperatureMaxK' => 6500,
                            ];
                        }
                    }
                    break;

                case GoogleHomeGateway::TYPE_BLIND:
                    $traits[] = 'action.devices.traits.OpenClose';
                    $attrs['openDirection'] = ['UP', 'DOWN'];
                    break;

                case GoogleHomeGateway::TYPE_THERMOSTAT:
                    $traits[] = 'action.devices.traits.TemperatureSetting';
                    $attrs['availableThermostatModes'] = 'off,heat,on';
                    $attrs['thermostatTemperatureUnit'] = 'C';
                    break;

                case GoogleHomeGateway::TYPE_SCENE:
                    $traits[] = 'action.devices.traits.Scene';
                    // Szene ist deaktivierbar (reversible), wenn eine Ausschalt-Aktion hinterlegt ist
                    $actionOff = $device['ActionOff'] ?? '{}';
                    $attrs['sceneReversible'] = ($actionOff !== '{}' && !empty($actionOff));
                    break;
            }

            $willReportState = ($type !== GoogleHomeGateway::TYPE_SCENE);

            $syncDev = [
                'id'              => (string)($device['id']),
                'type'            => GoogleHomeGateway::GOOGLE_TYPES[$type] ?? 'action.devices.types.SWITCH',
                'traits'          => $traits,
                'name'            => ['name' => $device['name'] ?? 'Unbekannt'],
                'willReportState' => $willReportState,
            ];

            if (!empty($device['room'])) {
                $syncDev['roomHint'] = $device['room'];
            }

            if (!empty($attrs)) {
                $syncDev['attributes'] = $attrs;
            }

            return $syncDev;
        }
    }
}
