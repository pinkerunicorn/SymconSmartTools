<?php

declare(strict_types=1);

class UniversalDeviceScanner extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->RegisterPropertyString('ExcludeConfigurators', '[]');
        $this->RegisterPropertyString('ExcludeInstances', '[]');
        
        $this->RegisterVariableInteger('ScannedDevices', 'Gescannte Geräte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'magnifying-glass'
        ], 1);
        $this->RegisterVariableInteger('RegisteredDevices', 'Registrierte Geräte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'check'
        ], 2);
        $this->RegisterVariableInteger('SkippedDevices', 'Übersprungen (Wrapper/Trait)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'forward'
        ], 3);
        $this->RegisterVariableString('LastScan', 'Letzter Scan', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock'
        ], 5);
        $this->RegisterVariableString('ScanLog', 'Scan-Protokoll', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'file-lines'
        ], 10);
    }
    
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID > 0 && @IPS_InstanceExists($registryID)) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }
    }
    
    public function Scan(): string
    {
        $results = [];
        $scanned = 0;
        $skipped = 0;
        $registered = 0;
        
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID === 0 || !@IPS_InstanceExists($registryID)) {
            echo 'Keine Device Registry konfiguriert!';
            return '[]';
        }
        
        // Exclude-Liste laden
        $excludeJson = $this->ReadPropertyString('ExcludeConfigurators');
        $excludeList = json_decode($excludeJson, true) ?: [];
        $excludeIDs = array_column($excludeList, 'instanceID');
        
        // Bereits per Trait registrierte Instanzen laden (für Dedup)
        $autoRegistered = [];
        if (function_exists('SDR_GetAutoRegistered')) {
            $autoRegistered = json_decode(@SDR_GetAutoRegistered($registryID), true) ?: [];
        }
        
        $traitInstanceIDs = [];
        foreach ($autoRegistered as $dev) {
            if (($dev['source'] ?? '') === 'trait') {
                $traitInstanceIDs[] = $dev['instanceID'] ?? 0;
            }
        }
        
        // Alle Konfiguratoren finden (ModuleType 4)
        $configurators = $this->findAllConfigurators();
        $log = [];
        
        foreach ($configurators as $conf) {
            if (in_array($conf['instanceID'], $excludeIDs)) {
                $log[] = 'SKIP Konfigurator: ' . $conf['name'] . ' (ausgeschlossen)';
                continue;
            }
            
            $log[] = 'Scanne: ' . $conf['name'] . ' (' . $conf['moduleName'] . ')';
            
            // Geraete vom Konfigurator holen
            $devices = $this->getDevicesFromConfigurator($conf['instanceID']);
            
            foreach ($devices as $device) {
                $instanceID = $device['instanceID'] ?? 0;
                if ($instanceID === 0 || !@IPS_InstanceExists($instanceID)) {
                    continue; // Gerät nicht in Symcon angelegt
                }
                
                $scanned++;
                
                // Dedup: Bereits per Trait registriert?
                if (in_array($instanceID, $traitInstanceIDs)) {
                    $skipped++;
                    $log[] = '  SKIP (Trait): ' . ($device['name'] ?? IPS_GetName($instanceID));
                    $results[] = [
                        'configurator' => $conf['name'],
                        'name'         => $device['name'] ?? IPS_GetName($instanceID),
                        'type'         => 'trait_managed',
                        'room'         => '',
                        'floor'        => '',
                        'hasBattery'   => '',
                        'hasReachable' => '',
                        'source'       => 'trait',
                        'status'       => 'Eigenes Modul'
                    ];
                    continue;
                }
                
                // Variablen der Instanz mappen
                $variables = $this->mapInstanceVariables($instanceID);
                
                // Für HM-IP: Maintenance-Kanal suchen (:0 hat UNREACH, LOW_BAT)
                $maintenanceID = $this->findMaintenanceChannel($instanceID);
                if ($maintenanceID !== null) {
                    $maintVars = $this->mapInstanceVariables($maintenanceID);
                    // Maintenance-Variablen übernehmen wenn nicht schon vorhanden
                    foreach ($maintVars as $key => $varID) {
                        if (!isset($variables[$key])) {
                            $variables[$key] = $varID;
                        }
                    }
                }
                
                // Typ erkennen
                $deviceName = $device['name'] ?? IPS_GetName($instanceID);
                $deviceType = $this->detectDeviceType($variables, $deviceName);
                
                // Standort auflösen
                $location = IPS_GetLocation($instanceID);
                $resolved = [];
                if (function_exists('SDR_ResolveLocation')) {
                    $resolved = json_decode(@SDR_ResolveLocation($registryID, $location), true) ?: [];
                }
                $room = $resolved['room'] ?? '';
                $floor = $resolved['floor'] ?? '';
                
                $registered++;
                
                $results[] = [
                    'configurator' => $conf['name'],
                    'name'         => $deviceName,
                    'type'         => $deviceType,
                    'room'         => $room,
                    'floor'        => $floor,
                    'hasBattery'   => ($variables['Battery_VarID'] ?? 0) > 0 ? 'Ja' : '',
                    'hasReachable' => ($variables['Reachable_VarID'] ?? 0) > 0 ? 'Ja' : '',
                    'source'       => 'scan',
                    'status'       => 'Gefunden',
                    // Interne Daten für RegisterAll:
                    'instanceID'   => $instanceID,
                    'variables'    => $variables,
                    'location'     => $location,
                ];
                
                $log[] = '  OK: ' . $deviceName . ' -> ' . $deviceType . ' (' . $room . ')';
            }
        }
        
        $this->SetValue('ScannedDevices', $scanned);
        $this->SetValue('RegisteredDevices', $registered);
        $this->SetValue('SkippedDevices', $skipped);
        $this->SetValue('LastScan', date('d.m.Y H:i:s'));
        $this->SetValue('ScanLog', implode("\n", $log));
        
        $this->UpdateFormField('ScanResults', 'values', json_encode($results));
        
        echo 'Scan abgeschlossen: ' . $scanned . ' gescannt, ' . $registered . ' gefunden, ' . $skipped . ' übersprungen.';
        return json_encode($results);
    }
    
    public function RegisterAll(): void
    {
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID === 0) {
            echo 'Keine Device Registry konfiguriert!';
            return;
        }
        
        // Letztes Scan-Ergebnis erneut erzeugen
        $resultJson = $this->Scan();
        $results = json_decode($resultJson, true) ?: [];
        
        $count = 0;
        foreach ($results as $result) {
            if (($result['source'] ?? '') !== 'scan') continue;
            $instanceID = $result['instanceID'] ?? 0;
            if ($instanceID === 0) continue;
            
            $payload = json_encode([
                'instanceID'  => $instanceID,
                'moduleGUID'  => @IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'] ?? '',
                'type'        => $result['type'],
                'name'        => $result['name'],
                'location'    => $result['location'] ?? '',
                'room'        => $result['room'],
                'floor'       => $result['floor'],
                'variables'   => $result['variables'] ?? [],
                'source'      => 'scan',
            ]);
            
            if (function_exists('SDR_AutoRegister') && @SDR_AutoRegister($registryID, $payload)) {
                $count++;
            }
        }
        
        echo $count . ' Geräte erfolgreich in der Device Registry registriert.';
    }
    
    private function findAllConfigurators(): array
    {
        $configurators = [];
        $instanceList = @IPS_GetInstanceList();
        if (!is_array($instanceList)) return [];
        
        foreach ($instanceList as $instID) {
            $inst = @IPS_GetInstance($instID);
            if (!$inst) continue;
            
            $module = $inst['ModuleInfo'] ?? null;
            if ($module && isset($module['ModuleType']) && $module['ModuleType'] === 4) {
                $configurators[] = [
                    'instanceID' => $instID,
                    'name'       => IPS_GetName($instID),
                    'moduleGUID' => $module['ModuleID'] ?? '',
                    'moduleName' => $module['ModuleName'] ?? '',
                ];
            }
        }
        return $configurators;
    }
    
    private function getDevicesFromConfigurator(int $configuratorID): array
    {
        $formJson = @IPS_GetConfigurationForm($configuratorID);
        if ($formJson === false || $formJson === '') return [];
        $form = json_decode($formJson, true);
        if (!is_array($form)) return [];
        
        // Suche das Configurator-Element in elements UND actions
        $searchIn = array_merge($form['elements'] ?? [], $form['actions'] ?? []);
        foreach ($searchIn as $el) {
            if (($el['type'] ?? '') === 'Configurator') {
                return $el['values'] ?? [];
            }
        }
        return [];
    }
    
    private function mapInstanceVariables(int $instanceID): array
    {
        $vars = [];
        $mappings = [
            'OnOff_VarID'        => ['STATE', 'Status', 'SWITCH', 'Power'],
            'Brightness_VarID'   => ['LEVEL', 'Brightness', 'Dimmer'],
            'Position_VarID'     => ['LEVEL', 'Position', 'Shutter'],
            'Temperature_VarID'  => ['ACTUAL_TEMPERATURE', 'Temperature', 'TEMPERATURE'],
            'SetPoint_VarID'     => ['SET_POINT_TEMPERATURE', 'SetPoint', 'SET_TEMPERATURE'],
            'Humidity_VarID'     => ['HUMIDITY', 'Humidity'],
            'Battery_VarID'      => ['LOW_BAT', 'OPERATING_VOLTAGE', 'BatteryLevel', 'Battery'],
            'Reachable_VarID'    => ['UNREACH'],
            'Power_VarID'        => ['POWER', 'ENERGY_COUNTER', 'Wattage'],
            'Motion_VarID'       => ['MOTION', 'MOTION_DETECTION', 'PRESENCE_DETECTION'],
            'Smoke_VarID'        => ['SMOKE_DETECTOR_ALARM_STATUS'],
            'Water_VarID'        => ['ALARMSTATE', 'MOISTURE_DETECTED'],
            'Wind_VarID'         => ['WIND_SPEED'],
            'Rain_VarID'         => ['RAINING'],
            'Illumination_VarID' => ['ILLUMINATION', 'CURRENT_ILLUMINATION'],
            'Status_VarID'       => ['STATE', 'Status'],
            'OpenClose_VarID'    => ['STATE'],
            'Energy_VarID'       => ['ENERGY_COUNTER', 'METER'],
        ];
        
        $children = @IPS_GetChildrenIDs($instanceID);
        if (!is_array($children)) return $vars;
        
        foreach ($children as $childID) {
            $obj = @IPS_GetObject($childID);
            if (!is_array($obj) || ($obj['ObjectType'] ?? -1) !== 2) continue; // nur Variablen
            
            $ident = $obj['ObjectIdent'] ?? '';
            if ($ident === '') continue;
            
            foreach ($mappings as $varKey => $identCandidates) {
                if (in_array($ident, $identCandidates, true) && !isset($vars[$varKey])) {
                    $vars[$varKey] = $childID;
                    break;
                }
            }
        }
        
        // UNREACH Sonderbehandlung: Flag setzen
        if (isset($vars['Reachable_VarID'])) {
            $vars['reachableInverted'] = true;
        }
        
        return $vars;
    }
    
    private function detectDeviceType(array $variables, string $name): string
    {
        // HM-IP Gerätetyp-Mapping (Prefix-Match)
        $typeMap = [
            'HmIP-SWDO'  => 'DevicesContactSensor', 'HmIP-SWDM'  => 'DevicesContactSensor',
            'HmIP-SRH'   => 'DevicesContactSensor',
            'HmIP-SMI'   => 'DevicesMotionSensor',   'HmIP-SMO'   => 'DevicesMotionSensor',
            'HmIP-SPI'   => 'DevicesMotionSensor',
            'HmIP-eTRV'  => 'DevicesThermostat',     'HmIP-STHD'  => 'DevicesThermostat',
            'HmIP-STH'   => 'DevicesThermostat',     'HmIP-WTH'   => 'DevicesThermostat',
            'HmIP-FALMOT'=> 'DevicesThermostat',
            'HmIP-BSM'   => 'DevicesSocket',         'HmIP-FSM'   => 'DevicesSocket',
            'HmIP-PS'    => 'DevicesSwitch',          'HmIP-PSM'   => 'DevicesSocket',
            'HmIP-BSL'   => 'DevicesSwitch',
            'HmIP-BDT'   => 'DevicesLightDimmer',    'HmIP-FDT'   => 'DevicesLightDimmer',
            'HmIP-PDT'   => 'DevicesLightDimmer',
            'HmIP-BROLL' => 'DevicesBlind',           'HmIP-FROLL' => 'DevicesBlind',
            'HmIP-BBL'   => 'DevicesBlind',           'HmIP-FBL'   => 'DevicesBlind',
            'HmIP-HDM'   => 'DevicesBlind',
            'HmIP-SWSD'  => 'DevicesSmokeSensor',
            'HmIP-SWD'   => 'DevicesAlarmSensor',
            'HmIP-SWO'   => 'DevicesGenericSensor',
            'HmIP-STHO'  => 'DevicesGenericSensor',
            'HmIP-DLD'   => 'DevicesSwitch',
            'HmIP-WRC2'  => 'DevicesWallSwitch',      'HmIP-WRC6'  => 'DevicesWallSwitch',
            'HmIP-WRCD'  => 'DevicesWallSwitch',      'HmIP-WRCR'  => 'DevicesWallSwitch',
            'HmIP-KRCA'  => 'DevicesWallSwitch',      'HmIP-KRC4'  => 'DevicesWallSwitch',
        ];
        
        foreach ($typeMap as $prefix => $type) {
            if (str_contains($name, $prefix)) return $type;
        }
        
        // Fallback: Aus Variablen ableiten
        if (!empty($variables['SetPoint_VarID']))   return 'DevicesThermostat';
        if (!empty($variables['Motion_VarID']))      return 'DevicesMotionSensor';
        if (!empty($variables['Smoke_VarID']))       return 'DevicesSmokeSensor';
        if (!empty($variables['Water_VarID']))       return 'DevicesAlarmSensor';
        if (!empty($variables['Brightness_VarID']))  return 'DevicesLightDimmer';
        if (!empty($variables['Position_VarID']))    return 'DevicesBlind';
        if (!empty($variables['OnOff_VarID']))       return 'DevicesSwitch';
        if (!empty($variables['Temperature_VarID'])) return 'DevicesGenericSensor';
        if (!empty($variables['OpenClose_VarID']))   return 'DevicesContactSensor';
        
        return 'DevicesGenericSensor';
    }
    
    private function findMaintenanceChannel(int $instanceID): ?int
    {
        $address = @IPS_GetProperty($instanceID, 'Address');
        if (!is_string($address) || !str_contains($address, ':')) return null;
        
        $baseAddress = explode(':', $address)[0];
        $maintenanceAddress = $baseAddress . ':0';
        
        $hmDeviceGUID = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';
        $instances = @IPS_GetInstanceListByModuleID($hmDeviceGUID);
        if (!is_array($instances)) return null;
        
        foreach ($instances as $instID) {
            if (@IPS_GetProperty($instID, 'Address') === $maintenanceAddress) {
                return $instID;
            }
        }
        return null;
    }
    
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }
}
