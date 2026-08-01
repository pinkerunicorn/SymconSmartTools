<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

/**
 * SmartDeviceMonitor — Modul zur automatischen Erkennung und Überwachung von leeren Batterien und Offline-Geräten.
 *
 * Sucht automatisch nach Variablen mit Profilen/Idents für Batterien und Erreichbarkeit.
 * Bietet eine einfache Visualisierung in der Tile View und pusht Benachrichtigungen
 * über den SmartNotifier, falls gewünscht.
 */
class SmartDeviceMonitor extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900); // Priorität: Info

        // Properties
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        $this->RegisterPropertyInteger('LowBatteryThreshold', 15);
        $this->RegisterPropertyString('CustomVariables', '[]');
        $this->RegisterPropertyString('CustomBatteryVariables', '[]');
        $this->RegisterPropertyString('CustomOfflineVariables', '[]');

        // Variablen (Status)
        $this->RegisterVariableInteger('LowBatteryCount', 'Schwache Batterien', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Battery'], 1);
        $this->RegisterVariableInteger('OfflineDeviceCount', 'Offline Geräte', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Warning'], 2);
        
        $this->RegisterVariableString('SummaryText', 'Status Zusammenfassung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 3);

        $this->RegisterVariableString('MonitoredListHTML', 'Überwachte Geräte (Übersicht)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Database'
        ], 10);

        // Timer (Täglich scannen, falls sich neue Geräte anmelden)
        $this->RegisterTimer('DailyScanTimer', 86400 * 1000, 'SDM_UpdateMonitoredDevices($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
        // Tile View aktivieren für eine schöne Anzeige
        $this->SetVisualizationType(1);
        $this->SetStatus(102);
        $this->DA_ApplyPresentation();

        $this->UpdateMonitoredDevices();
    }

    public function GetVisualizationTile(): string
    {
        $batCount = $this->GetValue('LowBatteryCount');
        $offCount = $this->GetValue('OfflineDeviceCount');
        $htmlList = $this->GetValue('MonitoredListHTML');
        
        $statusStyle = ($batCount > 0 || $offCount > 0) ? 'color: #ff3333; font-weight: bold;' : 'color: #33cc33; font-weight: bold;';
        $statusText = ($batCount > 0 || $offCount > 0) ? 'Fehlerhafte Geräte gefunden!' : 'Alles in bester Ordnung.';

        return <<<HTML
<div style="font-family: sans-serif; padding: 10px;">
    <h2>Smart Device Monitor</h2>
    <p>Automatische Erkennung von leeren Batterien & Offline-Geräten.</p>
    
    <div style="background-color: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
        <span style="{$statusStyle}">{$statusText}</span><br>
        Schwache Batterien: <b>{$batCount}</b> | Offline Geräte: <b>{$offCount}</b>
    </div>

    <h3>Detail-Übersicht</h3>
    <div style="background-color: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; overflow-x: auto; max-height: 400px; overflow-y: auto;">
        {$htmlList}
    </div>
</div>
HTML;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "SelectInstance",
            "name": "TargetNotifier",
            "caption": "SmartNotifier Instanz"
        },
        {
            "type": "NumberSpinner",
            "name": "LowBatteryThreshold",
            "caption": "Batterie Warnschwelle (%)",
            "minimum": 1,
            "maximum": 50
        },
        {
            "type": "List",
            "name": "CustomBatteryVariables",
            "caption": "Manuelle Variablen: Batterie",
            "columns": [
                {
                    "name": "VariableID",
                    "type": "SelectVariable",
                    "caption": "Variable",
                    "width": "100%"
                }
            ],
            "add": true,
            "delete": true
        },
        {
            "type": "List",
            "name": "CustomOfflineVariables",
            "caption": "Manuelle Variablen: Erreichbarkeit (On/Offline)",
            "columns": [
                {
                    "name": "VariableID",
                    "type": "SelectVariable",
                    "caption": "Variable",
                    "width": "100%"
                }
            ],
            "add": true,
            "delete": true
        },
        {
            "type": "List",
            "name": "CustomVariables",
            "caption": "Manuelle Variablen: Sonstige",
            "columns": [
                {
                    "name": "VariableID",
                    "type": "SelectVariable",
                    "caption": "Variable",
                    "width": "100%"
                }
            ],
            "add": true,
            "delete": true
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Jetzt alle Batterien & Geräte scannen",
            "onClick": "SDM_UpdateMonitoredDevices($id);"
        }
    ]
}
EOT;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->CheckHealth(true);
        }
    }

    public function UpdateMonitoredDevices(): void
    {
        $this->SendDebug('Info', 'Starte automatischen Scan nach Batterien und Offline-Geräten...', 0);

        $this->SetTimerInterval('DailyScanTimer', 86400 * 1000);

        $monitoredVars = [];

        $varIDs = IPS_GetVariableList();
        foreach ($varIDs as $vid) {
            $obj = IPS_GetObject($vid);
            $ident = strtoupper($obj['ObjectIdent']);
            $varName = $obj['ObjectName'];
            $var = IPS_GetVariable($vid);
            $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];

            if (in_array($ident, ['LOWBAT', 'LOW_BAT', 'BATTERY', 'BATTERY_STATE', 'BATTERY_LEVEL', 'BATTERIE', 'OPERATINGVOLTAGE'], true)) {
                $monitoredVars[] = $vid;
                continue;
            }

            if ($profile !== '' && (stripos($profile, 'battery') !== false || stripos($profile, 'batterie') !== false)) {
                $monitoredVars[] = $vid;
                continue;
            }

            // Fallback: Name contains Batterie/Battery (e.g. "Batterie schwach")
            if (stripos($varName, 'batterie') !== false || stripos($varName, 'battery') !== false || stripos($varName, 'lowbat') !== false) {
                $monitoredVars[] = $vid;
                continue;
            }

            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH', 'DEVICEAVAILABLE', 'OFFLINE'], true)) {
                $monitoredVars[] = $vid;
                continue;
            }
        }

        $customLists = ['CustomVariables', 'CustomBatteryVariables', 'CustomOfflineVariables'];
        foreach ($customLists as $propName) {
            $customJson = $this->ReadPropertyString($propName);
            $customList = json_decode($customJson, true);
            if (is_array($customList)) {
                foreach ($customList as $item) {
                    if (isset($item['VariableID']) && IPS_VariableExists((int)$item['VariableID'])) {
                        $monitoredVars[] = (int)$item['VariableID'];
                    }
                }
            }
        }

        $monitoredVars = array_unique($monitoredVars);

        foreach ($monitoredVars as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        $this->SendDebug('Info', count($monitoredVars) . ' Variablen zur Überwachung registriert.', 0);

        $this->CheckHealth(false);
    }

        public function CheckHealth(bool $triggerNotification = false): void
    {
        $threshold = $this->ReadPropertyInteger('LowBatteryThreshold');
        $lowBatteries = [];
        $offlineDevices = [];
        $htmlRowsBattery = [];
        $htmlRowsOffline = [];
        $htmlRowsCustom = [];

        $varIDs = IPS_GetVariableList();
        
        // Custom Variables laden
        $customVars = [];
        $customBatteryVars = [];
        $customOfflineVars = [];
        
        $loadCustomList = function($propName) {
            $list = [];
            $json = $this->ReadPropertyString($propName);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (isset($item['VariableID'])) {
                        $list[] = (int)$item['VariableID'];
                    }
                }
            }
            return $list;
        };

        $customVars = $loadCustomList('CustomVariables');
        $customBatteryVars = $loadCustomList('CustomBatteryVariables');
        $customOfflineVars = $loadCustomList('CustomOfflineVariables');

        foreach ($varIDs as $vid) {
            if (!IPS_VariableExists($vid)) continue;

            $obj = IPS_GetObject($vid);
            $varName = $obj['ObjectName'];
            $ident = strtoupper($obj['ObjectIdent']);
            $val = GetValue($vid);
            
            // Komplette Pfad-Hierarchie aus IP-Symcon holen, um maximale Klarheit zu schaffen
            $fullLoc = IPS_GetLocation($vid);
            // fullLoc sieht z.B. so aus: "Smarthome \ Erdgeschoss \ Wohnzimmer \ Thermostat \ Geräteinformationen \ Batterie"
            $pathParts = explode('\\', $fullLoc);
            // Letztes Element (den Variablen-Namen selbst) entfernen
            array_pop($pathParts);
            
            // Wenn der Pfad extrem lang ist, schneiden wir die obersten Level ab (z.B. Smarthome \ Erdgeschoss)
            // Wir behalten maximal die letzten 3 Ebenen des Geräts
            if (count($pathParts) > 3) {
                $pathParts = array_slice($pathParts, -3);
            }
            
            $deviceName = trim(implode(' / ', $pathParts));
            
            $var = IPS_GetVariable($vid);
            $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];

            $isMonitored = false;
            $type = 'unknown';
            $statusText = 'OK';
            $statusColor = '#00FF00';
            
            // Ist es manuell hinzugefügt?
            if (in_array($vid, $customBatteryVars, true)) {
                $isMonitored = true;
                $type = 'battery';
                if ($val === true) {
                    $lowBatteries[] = "$deviceName ($varName)";
                    $statusText = 'BATTERIE SCHWACH';
                    $statusColor = '#FF0000';
                } else {
                    $statusText = 'BATTERIE OK';
                }
            } elseif (in_array($vid, $customOfflineVars, true)) {
                $isMonitored = true;
                $type = 'offline';
                if ($val === true || $val === false) { // Boolean handling
                    // For typical Unreach variables, true = Offline. For DeviceAvailable, false = Offline.
                    // Assuming standard Unreach format (true = fault):
                    $isFault = ($val === true);
                    if ($isFault) {
                        $offlineDevices[] = "$deviceName ($varName)";
                        $statusText = 'OFFLINE';
                        $statusColor = '#FF9900';
                    } else {
                        $statusText = 'ONLINE';
                    }
                }
            } elseif (in_array($vid, $customVars, true)) {
                $isMonitored = true;
                $type = 'custom';
                // Bei manuellen Variablen versuchen wir zu raten
                if (is_bool($val)) {
                    if ($val === true) {
                        $statusText = 'AKTIV / OFFLINE';
                        $statusColor = '#FF9900';
                    } else {
                        $statusText = 'INAKTIV / ONLINE';
                    }
                } else {
                    $statusText = (string)$val;
                    $statusColor = '#FFFFFF';
                }
            }

            // Battery check (Auto-Detect)
            if (!$isMonitored && in_array($ident, ['LOWBAT', 'LOW_BAT', 'BATTERY_STATE'], true)) {
                $isMonitored = true;
                $type = 'battery';
                if ($val === true) {
                    $lowBatteries[] = "$deviceName ($varName)";
                    $statusText = 'BATTERIE SCHWACH';
                    $statusColor = '#FF0000';
                } else {
                    $statusText = 'BATTERIE OK';
                }
            } elseif ($ident === 'OPERATINGVOLTAGE') {
                $isMonitored = true;
                $type = 'battery';
                if (is_numeric($val) && $val < $threshold) {
                    $lowBatteries[] = "$deviceName ($val V)";
                    $statusText = "SPANNUNG NIEDRIG ($val V)";
                    $statusColor = '#FF0000';
                } else {
                    $statusText = "SPANNUNG OK (" . (is_numeric($val) ? "$val V" : $val) . ")";
                }
            } elseif (!$isMonitored && (in_array($ident, ['BATTERY', 'BATTERY_LEVEL'], true) || ($profile !== '' && (stripos($profile, 'battery') !== false || stripos($profile, 'batterie') !== false)) || stripos($varName, 'batterie') !== false || stripos($varName, 'battery') !== false || stripos($varName, 'lowbat') !== false)) {
                $isMonitored = true;
                $type = 'battery';
                if (is_numeric($val) && $val < $threshold) {
                    $lowBatteries[] = "$deviceName ($val %)";
                    $statusText = "BATTERIE NIEDRIG ($val %)";
                    $statusColor = '#FF0000';
                } else {
                    $statusText = "BATTERIE OK (" . (is_numeric($val) ? "$val %" : (is_bool($val) ? ($val ? 'Schwach' : 'Gut') : $val)) . ")";
                    if (is_bool($val) && $val === true) {
                        $lowBatteries[] = "$deviceName ($varName)";
                        $statusText = 'BATTERIE SCHWACH';
                        $statusColor = '#FF0000';
                    }
                }
            }

            // Offline check (Auto-Detect)
            if (!$isMonitored && in_array($ident, ['UNREACH', 'STICKY_UNREACH', 'OFFLINE'], true)) {
                $isMonitored = true;
                $type = 'offline';
                if ($val === true) {
                    $offlineDevices[] = "$deviceName ($varName)";
                    $statusText = 'OFFLINE';
                    $statusColor = '#FF9900';
                } else {
                    $statusText = 'ONLINE';
                }
            } elseif (!$isMonitored && $ident === 'DEVICEAVAILABLE') {
                $isMonitored = true;
                $type = 'offline';
                if ($val === false) {
                    $offlineDevices[] = "$deviceName ($varName)";
                    $statusText = 'OFFLINE';
                    $statusColor = '#FF9900';
                } else {
                    $statusText = 'ONLINE';
                }
            }

            // Build HTML table row for ALL monitored devices
            if ($isMonitored) {
                $row = "<tr><td style='width:50%;'><b>$deviceName</b></td><td style='width:30%;'>$varName</td><td style='width:20%; color:$statusColor;'><b>$statusText</b></td></tr>";
                if ($type === 'battery') {
                    $htmlRowsBattery[] = $row;
                } elseif ($type === 'offline') {
                    $htmlRowsOffline[] = $row;
                } else {
                    $htmlRowsCustom[] = $row;
                }
            }
        }

        $batCount = count($lowBatteries);
        $offCount = count($offlineDevices);

        $this->SetValue('LowBatteryCount', $batCount);
        $this->SetValue('OfflineDeviceCount', $offCount);

        $summary = [];
        if ($batCount > 0) {
            $summary[] = "Batterien leer ($batCount): " . implode(', ', $lowBatteries);
        }
        if ($offCount > 0) {
            $summary[] = "Offline Geräte ($offCount): " . implode(', ', $offlineDevices);
        }

        $text = count($summary) > 0 ? implode(' | ', $summary) : 'Alle Geräte betriebsbereit.';
        $this->SetValue('SummaryText', $text);

        $buildTable = function($title, $rows) {
            $t = "<div style='margin-top: 10px; margin-bottom: 5px; padding-bottom: 2px; border-bottom: 1px solid #555; color: #ddd; font-weight: bold; text-transform: uppercase;'>$title</div>";
            $t .= "<table style='width:100%; border-collapse:collapse; margin-bottom: 15px;'>";
            if (count($rows) > 0) {
                $t .= implode('', $rows);
            } else {
                $t .= "<tr><td colspan='3' style='color:#00FF00;'>Alle in Ordnung bzw. keine Geräte zur Überwachung gefunden.</td></tr>";
            }
            $t .= "</table>";
            return $t;
        };

        $html = $buildTable('Erreichbarkeit (On/Offline)', $htmlRowsOffline);
        $html .= $buildTable('Batteriestatus', $htmlRowsBattery);
        if (count($htmlRowsCustom) > 0) {
            $html .= $buildTable('Sonstige (Manuell)', $htmlRowsCustom);
        }

        $this->SetValue('MonitoredListHTML', $html);

        if ($triggerNotification && (count($lowBatteries) > 0 || count($offlineDevices) > 0)) {
            $notifierId = $this->ReadPropertyInteger('TargetNotifier');
            if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                $payload = json_encode([
                    'Title' => 'Geräteüberwachung',
                    'Message' => $text,
                    'Priority' => 1
                ]);
                @IPS_RunScriptText("NOTIFY_SendEvent($notifierId, " . var_export($payload, true) . ");");
            }
        }
    }
}