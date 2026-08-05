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
                    $on = (bool)($params['on'] ?? false);
                    $varId = (int)($device['OnOff_VarID'] ?? 0);
                    
                    if ($varId > 0 && IPS_ObjectExists($varId)) {
                        return $this->SetSymconValue($varId, $on);
                    }
                    
                    // Fallback für reine Dimmer ohne OnOff-Variable
                    $brightnessVarId = (int)($device['Brightness_VarID'] ?? 0);
                    if ($brightnessVarId > 0 && IPS_ObjectExists($brightnessVarId)) {
                        $varType = IPS_GetVariable($brightnessVarId)['VariableType'];
                        if ($on) {
                            $value = ($varType === 2) ? 1.0 : 100;
                        } else {
                            $value = ($varType === 2) ? 0.0 : 0;
                        }
                        return $this->SetSymconValue($brightnessVarId, $value);
                    }
                    return false;

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

                // ─── ActivateScene ─────────────────────────────────────────
                case 'action.devices.commands.ActivateScene':
                    $deactivate = (bool)($params['deactivate'] ?? false);
                    $actionJson = $deactivate ? ($device['ActionOff'] ?? '{}') : ($device['ActionOn'] ?? '{}');
                    if ($actionJson === '{}' || empty($actionJson)) {
                        return false;
                    }
                    try {
                        IPS_RunAction($actionJson, []);
                        $this->SendDebug('Execute', "IPS_RunAction (Scene) -> $actionJson", 0);
                        return true;
                    } catch (\Throwable $e) {
                        $this->SLogError('Execute Scene fehlgeschlagen', $e->getMessage());
                        return false;
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
                // Wenn eine Aktion konfiguriert ist, RequestAction nutzen
                if (HasAction($varId)) {
                    RequestAction($varId, $value);
                    $this->SendDebug('Execute', "RequestAction VarID=$varId -> " . json_encode($value), 0);
                } else {
                    $this->SLogWarning('Execute', "Variable $varId hat kein Aktionsskript. Fuehre Fallback auf SetValue aus, Hardware schaltet eventuell nicht!");
                    SetValue($varId, $value);
                    $this->SendDebug('Execute', "SetValue (Fallback) VarID=$varId -> " . json_encode($value), 0);
                }
                return true;
            } catch (\Exception $e) {
                $this->SLogError('Execute Action fehlgeschlagen', "VarID=$varId: " . $e->getMessage());
                return false;
            }
        }
    }
}
