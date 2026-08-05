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
        $this->RegisterPropertyString('DevicesSocket', '[]');
        $this->RegisterPropertyString('DevicesLight', '[]');
        $this->RegisterPropertyString('DevicesLightDimmer', '[]');
        $this->RegisterPropertyString('DevicesLightColor', '[]');
        $this->RegisterPropertyString('DevicesBlind', '[]');
        $this->RegisterPropertyString('DevicesThermostat', '[]');
        $this->RegisterPropertyString('DevicesScene', '[]');
        
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
        
        $this->RegisterVariableInteger('RegisteredDevices', 'Registrierte Geraete', '', 1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $mappings = [
            'Floors', 'Rooms', 'DevicesSwitch', 'DevicesSocket', 'DevicesLight', 'DevicesLightDimmer',
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat', 'DevicesScene',
            'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor', 'DevicesWaterSensor',
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
                $totalDevices++;
            }
            unset($device);
            if ($propChanged) {
                IPS_SetProperty($this->InstanceID, $propName, json_encode(array_values($devices)));
            }
        }

        if ($changed) {
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('RegisteredDevices', $totalDevices);
    }

    public function GetConfigurationForm(): string
    {
        $jsonForm = file_get_contents(__DIR__ . '/form.json');
        $form     = json_decode($jsonForm, true);
        
        $floorsJson = $this->ReadPropertyString('Floors');
        $floorsList = json_decode($floorsJson, true);
        $floorOptions = [];
        if (is_array($floorsList)) {
            foreach ($floorsList as $f) {
                if (!empty($f['name'])) {
                    $floorOptions[] = ['caption' => $f['name'], 'value' => $f['name']];
                }
            }
        }
        
        $roomsJson = $this->ReadPropertyString('Rooms');
        $roomsList = json_decode($roomsJson, true);
        $roomOptions = [];
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

                                    if ($propName === 'DevicesScene') {
                                        if (empty($dev['ActionOn']) || $dev['ActionOn'] === '{}') {
                                            $status   = 'Aktion fehlt';
                                            $rowColor = '#FF8000'; 
                                            $hasError = true;
                                        }
                                    } else {
                                        $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID', 'Value_VarID'];
                                        $primaryFieldFound = false;
                                        foreach ($varFields as $varField) {
                                            if (isset($dev[$varField])) {
                                                $primaryFieldFound = true;
                                                if ($dev[$varField] > 0) {
                                                    if (!IPS_VariableExists((int)$dev[$varField])) {
                                                        $status   = 'Variable fehlt';
                                                        $rowColor = '#FF4040'; 
                                                        $hasError = true;
                                                        break;
                                                    }
                                                } else {
                                                    if (in_array($varField, ['OnOff_VarID', 'OpenClose_VarID', 'Status_VarID', 'Value_VarID'])) {
                                                        $status   = 'Unvollstaendig';
                                                        $rowColor = '#FF8000';
                                                        $hasError = true;
                                                    }
                                                }
                                            }
                                        }
                                        if (!$primaryFieldFound && !$hasError && $propName !== 'DevicesThermostat') {
                                             $status   = 'Unvollstaendig';
                                             $rowColor = '#FF8000'; 
                                        }
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
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat', 'DevicesScene',
            'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor', 'DevicesWaterSensor'
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
        $json = $this->ReadPropertyString($type);
        $list = json_decode($json, true);
        if (is_array($list)) {
            foreach ($list as &$dev) {
                $dev['Type'] = $type;
            }
            return $list;
        }
        return [];
    }

    public function GetDeviceVariables(string $deviceId): array
    {
        $devices = $this->GetDevices();
        foreach ($devices as $dev) {
            if (isset($dev['id']) && $dev['id'] === $deviceId) {
                $vars = [];
                $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID'];
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

    public function ClearList(string $listName): void
    {
        if (str_starts_with($listName, 'Devices')) {
            IPS_SetProperty($this->InstanceID, $listName, '[]');
            IPS_ApplyChanges($this->InstanceID);
            echo "Die Liste wurde komplett geleert! Bitte die Seite neu laden.";
        }
    }

    // Auto-Discovery Funktion
    public function DiscoverDevices(string $category = ''): void
    {
        $variables = IPS_GetVariableList();
        
        $existingVars = [];
        $devices = $this->GetDevices();
        foreach ($devices as $dev) {
            $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID'];
            foreach ($varFields as $field) {
                if (!empty($dev[$field])) {
                    $existingVars[] = (int)$dev[$field];
                }
            }
        }

        $newDevices = [
            'DevicesSwitch' => [],
            'DevicesSocket' => [],
            'DevicesLight' => [],
            'DevicesLightDimmer' => [],
            'DevicesBlind' => [],
            'DevicesThermostat' => [],
            'DevicesMotionSensor' => [],
            'DevicesContactSensor' => [],
            'DevicesSmokeSensor' => [],
            'DevicesWaterSensor' => []
        ];

        foreach ($variables as $varId) {
            if (in_array($varId, $existingVars)) {
                continue;
            }
            
            $var = IPS_GetVariable($varId);
            $obj = IPS_GetObject($varId);
            $profile = (string)(!empty($var['VariableCustomProfile']) ? $var['VariableCustomProfile'] : $var['VariableProfile']);
            $action = (int)(!empty($var['VariableCustomAction']) ? $var['VariableCustomAction'] : $var['VariableAction']);
            $hasAction = ($action > 0);
            
            $name = $obj['ObjectName'];
            $parentId = $obj['ParentID'];
            $room = IPS_ObjectExists($parentId) ? IPS_GetObject($parentId)['ObjectName'] : 'Unbekannt'; 
            
            // Motion
            if ($var['VariableType'] === 0 && !$hasAction && (
                strpos(strtolower($profile), 'motion') !== false ||
                strpos(strtolower($name), 'motion') !== false ||
                strpos(strtolower($name), 'bewegung') !== false ||
                (strpos(strtolower($name), 'status') !== false && (strpos(strtolower($room), 'bewegung') !== false || strpos(strtolower($room), 'motion') !== false))
            )) {
                // Ignore config variables like "Bewegungserkennung aktiv" (which has an action usually, but just in case)
                if (strpos(strtolower($name), 'aktiv') === false) {
                    $newDevices['DevicesMotionSensor'][] = [
                        'name' => $name,
                        'room' => $room,
                        'Status_VarID' => $varId
                    ];
                    $existingVars[] = $varId;
                }
            }
            // Window/Door Contact
            elseif ($var['VariableType'] === 0 && !$hasAction && (
                strpos(strtolower($profile), 'window') !== false ||
                strpos(strtolower($name), 'fenster') !== false ||
                strpos(strtolower($name), 'tür') !== false ||
                strpos(strtolower($name), 'door') !== false ||
                strpos(strtolower($name), 'contact') !== false ||
                strpos(strtolower($name), 'kontakt') !== false ||
                (strpos(strtolower($name), 'status') !== false && (strpos(strtolower($room), 'fenster') !== false || strpos(strtolower($room), 'tür') !== false || strpos(strtolower($room), 'kontakt') !== false))
            )) {
                $newDevices['DevicesContactSensor'][] = [
                    'name' => $name,
                    'room' => $room,
                    'Status_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Smoke Sensor
            elseif (($var['VariableType'] === 0 || $var['VariableType'] === 1) && !$hasAction && (
                strpos(strtolower($profile), 'smoke') !== false ||
                strpos(strtolower($profile), 'rauch') !== false ||
                strpos(strtolower($name), 'rauch') !== false ||
                strpos(strtolower($name), 'smoke') !== false ||
                strpos(strtolower($name), 'brand') !== false ||
                (strpos(strtolower($name), 'alarm') !== false && (strpos(strtolower($room), 'rauch') !== false || strpos(strtolower($room), 'smoke') !== false || strpos(strtolower($room), 'brand') !== false))
            )) {
                $newDevices['DevicesSmokeSensor'][] = [
                    'name' => $name,
                    'room' => $room,
                    'Status_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Water Sensor
            elseif (($var['VariableType'] === 0 || $var['VariableType'] === 1) && !$hasAction && (
                strpos(strtolower($profile), 'water') !== false ||
                strpos(strtolower($profile), 'wasser') !== false ||
                strpos(strtolower($profile), 'leak') !== false ||
                strpos(strtolower($name), 'wasser') !== false ||
                strpos(strtolower($name), 'water') !== false ||
                strpos(strtolower($name), 'leak') !== false ||
                strpos(strtolower($name), 'leck') !== false ||
                (strpos(strtolower($name), 'alarm') !== false && (strpos(strtolower($room), 'wasser') !== false || strpos(strtolower($room), 'water') !== false))
            ) && strpos(strtolower($profile), 'volume') === false && strpos(strtolower($name), 'zähler') === false && strpos(strtolower($name), 'verbrauch') === false) {
                $newDevices['DevicesWaterSensor'][] = [
                    'name' => $name,
                    'room' => $room,
                    'Status_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Generic Alarm Sensor (Sabotage, Glass Break, etc)
            elseif (($var['VariableType'] === 0 || $var['VariableType'] === 1) && !$hasAction && (
                strpos(strtolower($profile), 'alarm') !== false ||
                strpos(strtolower($name), 'alarm') !== false ||
                strpos(strtolower($name), 'sabotage') !== false ||
                strpos(strtolower($name), 'tamper') !== false ||
                strpos(strtolower($name), 'glasbruch') !== false ||
                strpos(strtolower($name), 'erschütterung') !== false ||
                strpos(strtolower($name), 'vibration') !== false ||
                strpos(strtolower($name), 'panic') !== false ||
                strpos(strtolower($name), 'panik') !== false
            )) {
                $newDevices['DevicesAlarmSensor'][] = [
                    'name' => $name,
                    'room' => $room,
                    'Status_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Meter (Strom, Wasser, Gas)
            elseif (($var['VariableType'] === 1 || $var['VariableType'] === 2) && !$hasAction && (
                strpos(strtolower($profile), 'electricity') !== false || strpos(strtolower($profile), 'watt') !== false || strpos(strtolower($profile), 'power') !== false ||
                strpos(strtolower($profile), 'water.volume') !== false || strpos(strtolower($profile), 'gas') !== false ||
                strpos(strtolower($name), 'strom') !== false || strpos(strtolower($name), 'energie') !== false || strpos(strtolower($name), 'leistung') !== false ||
                strpos(strtolower($name), 'wasserzähler') !== false || strpos(strtolower($name), 'gaszähler') !== false ||
                strpos(strtolower($name), 'zähler') !== false || strpos(strtolower($name), 'zaehler') !== false || strpos(strtolower($name), 'verbrauch') !== false ||
                strpos(strtolower($name), 'meter') !== false
            )) {
                $type = 'Strom';
                if (strpos(strtolower($profile), 'water') !== false || strpos(strtolower($name), 'wasser') !== false) {
                    $type = 'Wasser';
                } elseif (strpos(strtolower($profile), 'gas') !== false || strpos(strtolower($name), 'gas') !== false) {
                    $type = 'Gas';
                }
                
                $newDevices['DevicesMeter'][] = [
                    'name' => $name,
                    'room' => $room,
                    'type' => $type,
                    'Value_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Blind / Jalousie / Rolllade
            elseif (($var['VariableType'] === 1 || $var['VariableType'] === 2) && $hasAction && (
                strpos(strtolower($profile), 'blind') !== false || 
                strpos(strtolower($profile), 'shutter') !== false || 
                strpos(strtolower($name), 'rollladen') !== false || 
                strpos(strtolower($name), 'jalousie') !== false ||
                strpos(strtolower($room), 'rollladen') !== false || 
                strpos(strtolower($room), 'jalousie') !== false
            )) {
                $newDevices['DevicesBlind'][] = [
                    'name' => $name,
                    'room' => $room,
                    'OpenClose_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Thermostat / Heizung
            elseif (($var['VariableType'] === 1 || $var['VariableType'] === 2) && $hasAction && (
                strpos(strtolower($profile), 'temperature') !== false ||
                strpos(strtolower($name), 'sollwert') !== false ||
                strpos(strtolower($name), 'zieltemperatur') !== false ||
                strpos(strtolower($name), 'setpoint') !== false ||
                strpos(strtolower($room), 'heizen') !== false ||
                strpos(strtolower($room), 'heizung') !== false ||
                strpos(strtolower($room), 'thermostat') !== false
            ) && strpos(strtolower($profile), 'color') === false && strpos(strtolower($name), 'color') === false && strpos(strtolower($name), 'farbe') === false && strpos(strtolower($name), 'farbtemperatur') === false) {
                $newDevices['DevicesThermostat'][] = [
                    'name' => $name,
                    'room' => $room,
                    'TempSet_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Dimmer
            elseif (($var['VariableType'] === 1 || $var['VariableType'] === 2) && $hasAction && strpos(strtolower($profile), 'intensity') !== false) {
                $newDevices['DevicesLightDimmer'][] = [
                    'name' => $name,
                    'room' => $room,
                    'Brightness_VarID' => $varId,
                    'OnOff_VarID' => 0
                ];
                $existingVars[] = $varId;
            }
            // Socket / Steckdose
            elseif ($var['VariableType'] === 0 && $hasAction && (strpos(strtolower($room), 'steckdose') !== false || strpos(strtolower($name), 'steckdose') !== false)) {
                $newDevices['DevicesSocket'][] = [
                    'name' => $name,
                    'room' => $room,
                    'OnOff_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Light (On/Off)
            elseif ($var['VariableType'] === 0 && $hasAction && (
                strpos(strtolower($name), 'licht') !== false || 
                strpos(strtolower($name), 'lampe') !== false || 
                strpos(strtolower($name), 'leuchte') !== false || 
                strpos(strtolower($name), 'light') !== false ||
                strpos(strtolower($room), 'licht') !== false || 
                strpos(strtolower($room), 'lampe') !== false || 
                strpos(strtolower($room), 'leuchte') !== false || 
                strpos(strtolower($room), 'light') !== false
            )) {
                $newDevices['DevicesLight'][] = [
                    'name' => $name,
                    'room' => $room,
                    'OnOff_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Switch
            elseif ($var['VariableType'] === 0 && $hasAction && (strpos(strtolower($profile), 'switch') !== false || strpos(strtolower($name), 'schalter') !== false || strpos(strtolower($name), 'status') !== false)) {
                // Bei generischem "Status" prüfen ob es vielleicht ein Licht oder Schalter ist
                $newDevices['DevicesSwitch'][] = [
                    'name' => $name,
                    'room' => $room,
                    'OnOff_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
            // Gerätestatus / Health
            elseif (($var['VariableType'] === 0 || $var['VariableType'] === 1) && !$hasAction && (
                strpos(strtolower($profile), 'reachable') !== false ||
                strpos(strtolower($profile), 'online') !== false ||
                strpos(strtolower($profile), 'availability') !== false ||
                strpos(strtolower($name), 'gerätestatus') !== false ||
                strpos(strtolower($name), 'geraetestatus') !== false ||
                strpos(strtolower($name), 'erreichbarkeit') !== false ||
                strpos(strtolower($name), 'online') !== false ||
                strpos(strtolower($name), 'offline') !== false ||
                strpos(strtolower($name), 'reachable') !== false ||
                strpos(strtolower($name), 'available') !== false
            )) {
                $newDevices['DevicesHealth'][] = [
                    'name' => $name,
                    'room' => $room,
                    'Status_VarID' => $varId
                ];
                $existingVars[] = $varId;
            }
        }
        
        $changed = false;
        $count = 0;
        foreach ($newDevices as $propName => $list) {
            if ($category !== '' && $category !== $propName) {
                continue;
            }

            if (count($list) > 0) {
                $existingJson = $this->ReadPropertyString($propName);
                $existingList = json_decode($existingJson, true) ?: [];
                $merged = array_merge($existingList, $list);
                IPS_SetProperty($this->InstanceID, $propName, json_encode($merged));
                $changed = true;
                $count += count($list);
            }
        }
        
        if ($changed) {
            IPS_ApplyChanges($this->InstanceID);
            echo "Es wurden $count neue Geraete in dieser Kategorie gefunden und hinzugefuegt! Bitte die Seite neu laden.";
        } else {
            echo "Es wurden keine neuen, ungemappten Geraete in dieser Kategorie gefunden.";
        }
    }
}
