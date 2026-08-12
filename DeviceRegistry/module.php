<?php

declare(strict_types=1);

class SymconDeviceRegistry extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyString('Floors', '[]');
        $this->RegisterPropertyString('Rooms', '[]');

        // Aktorik
        $this->RegisterPropertyString('DevicesSwitch', '[]');
        
        // Sensorik
        $this->RegisterPropertyString('DevicesWallSwitch', '[]');
        $this->RegisterPropertyString('DevicesSocket', '[]');
        $this->RegisterPropertyString('DevicesLight', '[]');
        $this->RegisterPropertyString('DevicesLightDimmer', '[]');
        $this->RegisterPropertyString('DevicesLightColor', '[]');
        $this->RegisterPropertyString('DevicesBlind', '[]');
        $this->RegisterPropertyString('DevicesThermostat', '[]');
        
        // Sensorik
        $this->RegisterPropertyString('DevicesMotionSensor', '[]');
        $this->RegisterPropertyString('DevicesContactSensor', '[]');
        $this->RegisterPropertyString('DevicesSmokeSensor', '[]');
        $this->RegisterPropertyString('DevicesWaterSensor', '[]');
        $this->RegisterPropertyString('DevicesAlarmSensor', '[]');
        
        // Zähler
        $this->RegisterPropertyString('DevicesMeter', '[]');
        
        // Diagnose
        $this->RegisterPropertyString('DevicesHealth', '[]');
        
        $this->RegisterVariableInteger('RegisteredDevices', 'Gesamtanzahl Geraete', '', 1);
        
        $captions = [
            'DevicesSwitch' => 'Schalter',
            'DevicesSocket' => 'Steckdosen',
            'DevicesLight' => 'Licht (Schalter)',
            'DevicesLightDimmer' => 'Licht (Dimmer)',
            'DevicesLightColor' => 'Licht (Farbe)',
            'DevicesBlind' => 'Jalousien',
            'DevicesThermostat' => 'Thermostate',

            'DevicesWallSwitch' => 'Wandschalter / Taster',
            'DevicesMotionSensor' => 'Bewegungsmelder',
            'DevicesContactSensor' => 'Fenster-/Türkontakte',
            'DevicesSmokeSensor' => 'Rauchmelder',
            'DevicesWaterSensor' => 'Wassermelder',
            'DevicesAlarmSensor' => 'Alarmmelder',
            'DevicesMeter' => 'Zähler',
            'DevicesHealth' => 'Offline-Module'
        ];
        
        $pos = 10;
        foreach ($captions as $prop => $caption) {
            $this->RegisterVariableInteger("Count_" . $prop, "Anzahl " . $caption, '', $pos++);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $mappings = [
            'Floors', 'Rooms', 'DevicesSwitch', 'DevicesSocket', 'DevicesLight', 'DevicesLightDimmer',
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat',
            'DevicesWallSwitch', 'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor', 'DevicesWaterSensor',
            'DevicesAlarmSensor', 'DevicesMeter', 'DevicesHealth'
        ];
        
        $changed = false;
        $totalDevices = 0;
        foreach ($mappings as $propName) {
            $json = $this->ReadPropertyString($propName);
            $devices = json_decode($json, true);
            if (!is_array($devices)) continue;
            
            $propChanged = false;
            foreach ($devices as &$device) {
                if (empty($device['id'])) {
                    // Generate a stable unique ID
                    $device['id'] = md5(uniqid((string)mt_rand(), true));
                    $propChanged = true;
                    $changed = true;
                }
            }
            unset($device);
            if ($propChanged) {
                IPS_SetProperty($this->InstanceID, $propName, json_encode(array_values($devices)));
            }
            
            if (str_starts_with($propName, 'Devices')) {
                $count = count($devices);
                @$this->SetValue("Count_" . $propName, $count);
                $totalDevices += $count;
            }
        }

        if ($changed) {
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('RegisteredDevices', $totalDevices);
        $this->notifyDependentModules();
    }

    private function notifyDependentModules(): void
    {
        $allInstances = IPS_GetInstanceList();
        $myId = $this->InstanceID;
        $count = 0;
        
        foreach ($allInstances as $instId) {
            if ($instId === $myId) {
                continue;
            }
            $regId = @IPS_GetProperty($instId, 'RegistryID');
            if ($regId === $myId) {
                $count++;
                @IPS_ApplyChanges($instId);
            }
        }
        
        if ($count > 0) {
            $this->SendDebug('RegistryUpdate', "Triggered IPS_ApplyChanges on $count dependent instances.", 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        $jsonForm = file_get_contents(__DIR__ . '/form.json');
        $form     = json_decode($jsonForm, true);
        
        $floorsJson = $this->ReadPropertyString('Floors');
        $floorsList = json_decode($floorsJson, true);
        $floorOptions = [['caption' => '(Nicht zugewiesen)', 'value' => '']];
        if (is_array($floorsList)) {
            foreach ($floorsList as $f) {
                if (!empty($f['name'])) {
                    $floorOptions[] = ['caption' => $f['name'], 'value' => $f['name']];
                }
            }
        }
        
        $roomsJson = $this->ReadPropertyString('Rooms');
        $roomsList = json_decode($roomsJson, true);
        $roomOptions = [['caption' => '(Nicht zugewiesen)', 'value' => '']];
        if (is_array($roomsList)) {
            foreach ($roomsList as $r) {
                if (!empty($r['name'])) {
                    $roomOptions[] = ['caption' => $r['name'], 'value' => $r['name']];
                }
            }
        }

        if (is_array($form) && isset($form['elements'])) {
            foreach ($form['elements'] as &$element) {
                if (($element['type'] ?? '') === 'ExpansionPanel' && isset($element['items'])) {
                    foreach ($element['items'] as &$item) {
                        if (($item['type'] ?? '') === 'List' && isset($item['name'])) {
                            if ($item['name'] === 'Rooms') {
                                // Inject Floor Options
                                if (isset($item['columns'])) {
                                    foreach ($item['columns'] as &$col) {
                                        if ($col['name'] === 'floor' && isset($col['edit']['type']) && $col['edit']['type'] === 'Select') {
                                            $col['edit']['options'] = $floorOptions;
                                        }
                                    }
                                    unset($col);
                                }
                            } elseif (str_starts_with($item['name'], 'Devices')) {
                                // Inject Room Options
                                if (isset($item['columns'])) {
                                    foreach ($item['columns'] as &$col) {
                                        if ($col['name'] === 'room' && isset($col['edit']['type']) && $col['edit']['type'] === 'Select') {
                                            $col['edit']['options'] = $roomOptions;
                                        }
                                    }
                                    unset($col);
                                }
                                
                                $propName    = $item['name'];
                            $devicesJson = $this->ReadPropertyString($propName);
                            $devices     = json_decode($devicesJson, true);
                            if (is_array($devices)) {
                                foreach ($devices as &$dev) {
                                    $status   = 'OK';
                                    $rowColor = ''; 
                                    $hasError = false;

                                        $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID', 'Value_VarID', 'ActualTemp_VarID', 'BoostMode_VarID', 'ControlMode_VarID', 'Humidity_VarID'];
                                        $primaryFieldFound = false;
                                        
                                        $isDimmer = in_array($propName, ['DevicesLightDimmer', 'DevicesLightColor']);
                                        $hasBrightness = (isset($dev['Brightness_VarID']) && (int)$dev['Brightness_VarID'] > 0 && IPS_VariableExists((int)$dev['Brightness_VarID']));

                                        foreach ($varFields as $varField) {
                                            if (isset($dev[$varField])) {
                                                $val = (int)$dev[$varField];
                                                if ($val > 0) {
                                                    $primaryFieldFound = true;
                                                    if (!IPS_VariableExists($val)) {
                                                        $status   = 'Variable fehlt';
                                                        $rowColor = '#FF4040'; 
                                                        $hasError = true;
                                                        break;
                                                    }
                                                } else {
                                                    if ($varField === 'OnOff_VarID' && $isDimmer && $hasBrightness) {
                                                        // OK: Dimmer darf nur Brightness haben
                                                    } elseif (in_array($varField, ['OnOff_VarID', 'OpenClose_VarID', 'Status_VarID', 'Value_VarID', 'TempSet_VarID', 'ActualTemp_VarID'])) {
                                                        $status   = 'Unvollstaendig';
                                                        $rowColor = '#FF8000';
                                                        $hasError = true;
                                                    }
                                                }
                                            }
                                        }
                                        if (!$primaryFieldFound && !$hasError) {
                                             $status   = 'Unvollstaendig';
                                             $rowColor = '#FF8000'; 
                                        }

                                    $dev['Status']   = $status;
                                    if ($rowColor !== '') {
                                        $dev['rowColor'] = $rowColor;
                                    } else {
                                        unset($dev['rowColor']);
                                    }
                                }
                                unset($dev);
                                $item['values'] = $devices;
                            }
                        }
                        }
                    }
                    unset($item);
                }
            }
            unset($element);
        }

        return json_encode($form);
    }
    
    // API Methoden
    
    public function GetFloors(): array
    {
        $json = $this->ReadPropertyString('Floors');
        $list = json_decode($json, true);
        return is_array($list) ? $list : [];
    }
    
    public function GetRooms(): array
    {
        $json = $this->ReadPropertyString('Rooms');
        $list = json_decode($json, true);
        return is_array($list) ? $list : [];
    }
    
    public function GetDevices(): array
    {
        $allDevices = [];
        $mappings = [
            'DevicesSwitch', 'DevicesSocket', 'DevicesLight', 'DevicesLightDimmer',
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat',
            'DevicesWallSwitch', 'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor', 'DevicesWaterSensor'
        ];

        foreach ($mappings as $propName) {
            $json = $this->ReadPropertyString($propName);
            $list = json_decode($json, true);
            if (is_array($list)) {
                foreach ($list as $dev) {
                    $dev['Type'] = $propName;
                    $allDevices[] = $dev;
                }
            }
        }
        return $allDevices;
    }

    public function GetDevicesByType(string $type): array
    {
        $list = [];
        $json = @$this->ReadPropertyString($type);
        if ($json === false || $json === '') {
            $json = '[]';
        }
        $primaryList = json_decode((string)$json, true);
        if (is_array($primaryList)) {
            foreach ($primaryList as $dev) {
                $dev['Type'] = $type;
                $list[] = $dev;
            }
        }

        // Feature: Dimmers and Color Lights can cascade down to simpler types
        $extraTypes = [];
        $requiredVar = '';
        
        if ($type === 'DevicesLight' || $type === 'DevicesSwitch') {
            $extraTypes = ['DevicesLightDimmer', 'DevicesLightColor'];
            $requiredVar = 'OnOff_VarID';
        } elseif ($type === 'DevicesLightDimmer') {
            $extraTypes = ['DevicesLightColor'];
            $requiredVar = 'Brightness_VarID';
        }

        if (!empty($extraTypes) && $requiredVar !== '') {
            foreach ($extraTypes as $eType) {
                $eJson = @$this->ReadPropertyString($eType);
                if ($eJson === false || $eJson === '') {
                    $eJson = '[]';
                }
                $eList = json_decode((string)$eJson, true);
                if (is_array($eList)) {
                    foreach ($eList as $dev) {
                        if (!empty($dev[$requiredVar]) && (int)$dev[$requiredVar] > 0) {
                            // Only add if not already in the list (prevent duplicates by ID)
                            $found = false;
                            foreach ($list as $existingDev) {
                                if (($existingDev['id'] ?? '') === ($dev['id'] ?? '')) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $dev['Type'] = $eType;
                                $list[] = $dev;
                            }
                        }
                    }
                }
            }
        }

        return $list;
    }

    public function GetDeviceVariables(string $deviceId): array
    {
        $devices = $this->GetDevices();
        foreach ($devices as $dev) {
            if (isset($dev['id']) && $dev['id'] === $deviceId) {
                $vars = [];
                $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID', 'ActualTemp_VarID', 'BoostMode_VarID', 'ControlMode_VarID', 'Humidity_VarID'];
                foreach ($varFields as $field) {
                    if (isset($dev[$field]) && $dev[$field] > 0) {
                        $vars[$field] = (int)$dev[$field];
                    }
                }
                return $vars;
            }
        }
        return [];
    }

    }
