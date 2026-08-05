<?php

declare(strict_types=1);

/**
 * GoogleExecute_Trait — Verarbeitet den EXECUTE Intent von Google Home.
 *
 * Empfängt Google-Commands ("Mach das Licht an") und führt die
 * entsprechenden Symcon-Aktionen aus (RequestAction / SetValue).
 */
if (!trait_exists('GoogleExecute_Trait')) {
    trait GoogleExecute_Trait
    {
        protected function HandleExecute(string $requestId, array $commands): array
        {
            $devicesConfig = $this->GetDevices();
            $deviceMap     = [];
            foreach ($devicesConfig as $d) {
                $deviceMap[(string)$d['id']] = $d;
            }

            $results = [];

            foreach ($commands as $command) {
                $targetDevices = $command['devices'] ?? [];
                $executions    = $command['execution'] ?? [];

                foreach ($targetDevices as $targetDev) {
                    $devId  = (string)($targetDev['id'] ?? '');
                    $device = $deviceMap[$devId] ?? null;

                    if ($device === null) {
                        $results[] = [
                            'ids'       => [$devId],
                            'status'    => 'ERROR',
                            'errorCode' => 'deviceNotFound',
                        ];
                        continue;
                    }

                    $success = true;
                    $errCode = '';

                    foreach ($executions as $exec) {
                        $cmd    = $exec['command'] ?? '';
                        $params = $exec['params'] ?? [];

                        if (!$this->ExecuteCommand($device, $cmd, $params)) {
                            $success = false;
                            $errCode = 'notSupported';
                        }
                    }

                    if ($success) {
                        // Aktuellen Zustand nach Execute zurückgeben
                        $newState          = $this->BuildDeviceState($device);
                        $newState['online'] = true;
                        $results[] = [
                            'ids'    => [$devId],
                            'status' => 'SUCCESS',
                            'states' => $newState,
                        ];

                        // Sofort Report State pushen
                        if ($this->ReadPropertyString('HomeGraphAPIKey') !== '') {
                            $this->PushReportState([(string)$device['id'] => $newState]);
                        }
                    } else {
                        $results[] = [
                            'ids'       => [$devId],
                            'status'    => 'ERROR',
                            'errorCode' => $errCode,
                        ];
                    }
                }
            }

            $this->SetValue('LastExecute', date('d.m.Y H:i:s'));

            return [
                'requestId' => $requestId,
                'payload'   => ['commands' => $results],
            ];
        }

        /**
         * Führt einen einzelnen Google-Command auf einem Gerät aus.
         */
        private function ExecuteCommand(array $device, string $command, array $params): bool
        {
            $type = (int)($device['type'] ?? 0);

            switch ($command) {
                // ─── OnOff ────────────────────────────────────────────────
                case 'action.devices.commands.OnOff':
                    $varId = (int)($device['OnOff_VarID'] ?? 0);
                    if ($varId <= 0 || !IPS_ObjectExists($varId)) {
                        return false;
                    }
                    $on = (bool)($params['on'] ?? false);
                    return $this->SetSymconValue($varId, $on);

                // ─── Brightness ───────────────────────────────────────────
                case 'action.devices.commands.BrightnessAbsolute':
                    $varId = (int)($device['Brightness_VarID'] ?? 0);
                    if ($varId <= 0 || !IPS_ObjectExists($varId)) {
                        return false;
                    }
                    $brightness = (int)($params['brightness'] ?? 0);
                    // Ziel-Variable prüfen: Float 0.0–1.0 oder Integer 0–100?
                    $varType = IPS_GetVariable($varId)['VariableType'];
                    $value   = ($varType === 2) // Float
                        ? round($brightness / 100, 2)
                        : $brightness;
                    // OnOff-Variable mitziehen (wenn Dimmer = 0, Licht aus)
                    $onOffVarId = (int)($device['OnOff_VarID'] ?? 0);
                    if ($onOffVarId > 0 && IPS_ObjectExists($onOffVarId)) {
                        $this->SetSymconValue($onOffVarId, $brightness > 0);
                    }
                    return $this->SetSymconValue($varId, $value);

                // ─── Color (RGB) ───────────────────────────────────────────
                case 'action.devices.commands.ColorAbsolute':
                    // RGB
                    if (isset($params['color']['spectrumRgb'])) {
                        $varId = (int)($device['ColorRGB_VarID'] ?? 0);
                        if ($varId > 0 && IPS_ObjectExists($varId)) {
                            return $this->SetSymconValue($varId, (int)$params['color']['spectrumRgb']);
                        }
                    }
                    // Farbtemperatur (Kelvin)
                    if (isset($params['color']['temperature'])) {
                        $varId = (int)($device['ColorTemp_VarID'] ?? 0);
                        if ($varId > 0 && IPS_ObjectExists($varId)) {
                            return $this->SetSymconValue($varId, (int)$params['color']['temperature']);
                        }
                    }
                    return false;

                // ─── OpenClose (Jalousie) ──────────────────────────────────
                case 'action.devices.commands.OpenClose':
                    $varId = (int)($device['OpenClose_VarID'] ?? 0);
                    if ($varId <= 0 || !IPS_ObjectExists($varId)) {
                        return false;
                    }
                    $openPercent = (int)($params['openPercent'] ?? 0);
                    $varType     = IPS_GetVariable($varId)['VariableType'];
                    if ($varType === 0) {
                        // Boolean: 100 = auf, 0 = zu
                        return $this->SetSymconValue($varId, $openPercent >= 50);
                    } elseif ($varType === 2) {
                        // Float 0.0–1.0
                        return $this->SetSymconValue($varId, round($openPercent / 100, 2));
                    } else {
                        // Integer 0–100
                        return $this->SetSymconValue($varId, $openPercent);
                    }

                default:
                    $this->SendDebug('Execute', 'Unbekannter Command: ' . $command, 0);
                    return false;
            }
        }

        /**
         * Setzt einen Symcon-Variablenwert via RequestAction (wenn Aktion aktiv)
         * oder direkt via SetValue.
         */
        private function SetSymconValue(int $varId, mixed $value): bool
        {
            try {
                $var = IPS_GetVariable($varId);
                // Wenn eine Aktion konfiguriert ist, RequestAction nutzen
                if ($var['VariableAction'] > 0 || $var['VariableCustomAction'] > 0) {
                    RequestAction($varId, $value);
                } else {
                    SetValue($varId, $value);
                }
                $this->SendDebug('Execute', "SetValue VarID=$varId -> " . json_encode($value), 0);
                return true;
            } catch (\Exception $e) {
                $this->SLogError('Execute SetValue fehlgeschlagen', "VarID=$varId: " . $e->getMessage());
                return false;
            }
        }
    }
}
