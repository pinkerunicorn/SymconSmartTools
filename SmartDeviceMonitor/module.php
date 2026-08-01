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
            "name": "CustomVariables",
            "caption": "Zusätzliche / Manuelle Variablen",
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

            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH', 'DEVICEAVAILABLE', 'OFFLINE'], true)) {
                $monitoredVars[] = $vid;
                continue;
            }
        }

        $customJson = $this->ReadPropertyString('CustomVariables');
        $customList = json_decode($customJson, true);
        if (is_array($customList)) {
            foreach ($customList as $item) {
                if (isset($item['VariableID']) && IPS_VariableExists((int)$item['VariableID'])) {
                    $monitoredVars[] = (int)$item['VariableID'];
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
        $htmlRows = [];

        $varIDs = IPS_GetVariableList();
        foreach ($varIDs as $vid) {
            if (!IPS_VariableExists($vid)) {
                continue;
            }

            $obj = IPS_GetObject($vid);
            $parentObj = IPS_GetObject($obj['ParentID']);
            $deviceName = $parentObj['ObjectName'];
            $varName = $obj['ObjectName'];
            $ident = strtoupper($obj['ObjectIdent']);
            $val = GetValue($vid);

            $statusText = 'OK';
            $statusColor = '#00FF00';

            if (in_array($ident, ['LOWBAT', 'LOW_BAT', 'BATTERY_STATE'], true) && $val === true) {
                $lowBatteries[] = "$deviceName ($varName)";
                $statusText = 'BATTERIE SCHWACH';
                $statusColor = '#FF0000';
            } elseif ($ident === 'OPERATINGVOLTAGE' && is_numeric($val) && $val < $threshold) {
                $lowBatteries[] = "$deviceName ($val V)";
                $statusText = "SPANNUNG NIEDRIG ($val V)";
                $statusColor = '#FF0000';
            } elseif (in_array($ident, ['BATTERY', 'BATTERY_LEVEL'], true) && is_numeric($val) && $val < $threshold) {
                $lowBatteries[] = "$deviceName ($val %)";
                $statusText = "BATTERIE NIEDRIG ($val %)";
                $statusColor = '#FF0000';
            }

            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH'], true) && $val === true) {
                $offlineDevices[] = "$deviceName ($varName)";
                $statusText = 'OFFLINE';
                $statusColor = '#FF9900';
            } elseif (in_array($ident, ['DEVICEAVAILABLE', 'OFFLINE'], true) && $val === false) {
                $offlineDevices[] = "$deviceName (Offline)";
                $statusText = 'OFFLINE';
                $statusColor = '#FF9900';
            }

            if ($statusText !== 'OK') {
                $htmlRows[] = "<tr><td><b>$deviceName</b></td><td>$varName</td><td style='color:$statusColor;'><b>$statusText</b></td></tr>";
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

        $html = "<table style='width:100%; border-collapse:collapse;'>";
        $html .= "<tr style='text-align:left;'><th>Gerät</th><th>Variable</th><th>Status</th></tr>";
        if (count($htmlRows) > 0) {
            $html .= implode('', $htmlRows);
        } else {
            $html .= "<tr><td colspan='3' style='color:#00FF00;'>Alle überwachten Geräte sind OK</td></tr>";
        }
        $html .= "</table>";
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