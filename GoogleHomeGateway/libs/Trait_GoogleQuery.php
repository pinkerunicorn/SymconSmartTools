<?php

declare(strict_types=1);

/**
 * GoogleQuery_Trait — Verarbeitet den QUERY Intent von Google Home.
 *
 * Liest den aktuellen Zustand aller angefragten Geräte aus den
 * konfigurierten Symcon-Variablen und gibt sie im Google-Format zurück.
 */
if (!trait_exists('GoogleQuery_Trait')) {
    trait GoogleQuery_Trait
    {
        protected function HandleQuery(string $requestId, array $requestedDevices): array
        {
            $devicesConfig = $this->GetDevices();
            // Index nach ID für schnellen Zugriff
            $deviceMap = [];
            foreach ($devicesConfig as $d) {
                $deviceMap[(string)$d['id']] = $d;
            }

            $states = [];
            foreach ($requestedDevices as $reqDev) {
                $devId = (string)($reqDev['id'] ?? '');
                if (isset($deviceMap[$devId])) {
                    $states[$devId] = $this->BuildDeviceState($deviceMap[$devId]);
                } else {
                    $states[$devId] = ['status' => 'ERROR', 'errorCode' => 'deviceNotFound'];
                }
            }

            return [
                'requestId' => $requestId,
                'payload'   => ['devices' => $states],
            ];
        }

        /**
         * Liest alle relevanten Variablenwerte eines Geräts und gibt sie
         * im Google Home State-Format zurück.
         */
        public function BuildDeviceState(array $device): array
        {
            $type  = (int)($device['type'] ?? 0);
            $state = ['status' => 'SUCCESS', 'online' => true];

            // OnOff
            $onOffVarId = (int)($device['OnOff_VarID'] ?? 0);
            if ($onOffVarId > 0 && IPS_ObjectExists($onOffVarId)) {
                $val = GetValue($onOffVarId);
                // Boolean direkt, Integer > 0 = an
                $state['on'] = is_bool($val) ? $val : ($val > 0);
            } else {
                // Fallback für Dimmer, die nur Intensität haben
                $brightnessVarId = (int)($device['Brightness_VarID'] ?? 0);
                if ($brightnessVarId > 0 && IPS_ObjectExists($brightnessVarId)) {
                    $brightness = GetValue($brightnessVarId);
                    $state['on'] = ($brightness > 0);
                } else {
                    $state['on'] = false;
                }
            }

            switch ($type) {
                case GoogleHomeGateway::TYPE_LIGHT_DIM:
                case GoogleHomeGateway::TYPE_LIGHT_COLOR:
                    // Brightness (0–100)
                    $brightnessVarId = (int)($device['Brightness_VarID'] ?? 0);
                    if ($brightnessVarId > 0 && IPS_ObjectExists($brightnessVarId)) {
                        $brightness = GetValue($brightnessVarId);
                        // Manche Module liefern 0.0–1.0, andere 0–100
                        if (is_float($brightness) && $brightness <= 1.0) {
                            $brightness = (int)round($brightness * 100);
                        }
                        $state['brightness'] = max(0, min(100, (int)$brightness));
                    }

                    if ($type === GoogleHomeGateway::TYPE_LIGHT_COLOR) {
                        // RGB
                        $colorRgbVarId = (int)($device['ColorRGB_VarID'] ?? 0);
                        if ($colorRgbVarId > 0 && IPS_ObjectExists($colorRgbVarId)) {
                            $state['color'] = ['spectrumRgb' => (int)GetValue($colorRgbVarId)];
                        }
                        // ColorTemperature (Kelvin)
                        $colorTempVarId = (int)($device['ColorTemp_VarID'] ?? 0);
                        if ($colorTempVarId > 0 && IPS_ObjectExists($colorTempVarId)) {
                            $state['color'] = array_merge($state['color'] ?? [], [
                                'temperatureK' => (int)GetValue($colorTempVarId),
                            ]);
                        }
                    }
                    break;

                case GoogleHomeGateway::TYPE_BLIND:
                    // OpenClose (0=geschlossen, 100=offen)
                    $openCloseVarId = (int)($device['OpenClose_VarID'] ?? 0);
                    if ($openCloseVarId > 0 && IPS_ObjectExists($openCloseVarId)) {
                        $val = GetValue($openCloseVarId);
                        if (is_bool($val)) {
                            $state['openPercent'] = $val ? 100 : 0;
                        } elseif (is_float($val) && $val <= 1.0) {
                            // 0.0–1.0 → 0–100
                            $state['openPercent'] = (int)round($val * 100);
                        } else {
                            $state['openPercent'] = max(0, min(100, (int)$val));
                        }
                    } else {
                        $state['openPercent'] = 0;
                    }
                    // Rollladen haben kein OnOff
                    unset($state['on']);
                    break;

                case GoogleHomeGateway::TYPE_THERMOSTAT:
                    $tempSetVarId = (int)($device['TempSet_VarID'] ?? 0);
                    if ($tempSetVarId > 0 && IPS_ObjectExists($tempSetVarId)) {
                        $val = (float)GetValue($tempSetVarId);
                        $state['thermostatMode'] = 'heat';
                        $state['thermostatTemperatureSetpoint'] = $val;
                        $state['thermostatTemperatureAmbient'] = $val; // Mangels separater Variable den Soll-Wert als Ist-Wert melden
                    }
                    unset($state['on']);
                    break;
            }

            return $state;
        }
    }
}
