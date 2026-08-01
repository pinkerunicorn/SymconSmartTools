<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

/**
 * SmartDeviceMonitor
 * Vollautomatischer Monitor fÃƒÆ’Ã‚Â¼r BatteriestÃƒÆ’Ã‚Â¤nde, GerÃƒÆ’Ã‚Â¤testatus (Offline) und Updates.
 *
 * @author Florian GraÃƒÆ’Ã…Â¸inger
 * @url https://github.com/pinkerunicorn/SymconSmartTools/tree/main/SmartDeviceMonitor
 */
class SmartDeviceMonitor extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        // DeviceAvailability setup
        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        $this->RegisterPropertyInteger('LowBatteryThreshold', 15);
        $this->RegisterPropertyString('CustomVariables', '[]');

        // Primary Variables (Read-only)
        $this->RegisterVariableInteger('LowBatteryCount', 'Leere Batterien', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'battery-10'
        ], 1);

        $this->RegisterVariableInteger('OfflineDeviceCount', 'Offline GerÃƒÆ’Ã‚Â¤te', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'alert-triangle'
        ], 2);

        $this->RegisterVariableString('SummaryText', 'Status Zusammenfassung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'information'
        ], 100);

        $this->RegisterVariableString('MonitoredListHTML', 'ÃƒÆ’Ã…â€œberwachte GerÃƒÆ’Ã‚Â¤te (ÃƒÆ’Ã…â€œbersicht)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'list'
        ], 101);

        // Timer for daily scan / resubscribe (at 03:00 AM)
        $this->RegisterTimer('DailyScanTimer', 0, 'SDM_UpdateMonitoredDevices($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->DA_ApplyPresentation();

        // Custom presentations for read-only variables
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('LowBatteryCount'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'battery-10',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([])
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('OfflineDeviceCount'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'alert-triangle',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([])
        ]);

        // Auto-Generate References
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
            $this->RegisterReference($notifierId);
        }

        // Schedule daily scan at 3 AM
        $now = new DateTime();
        $target = new DateTime('03:00:00');
        if ($now > $target) {
            $target->modify('+1 day');
        }
        $interval = $target->getTimestamp() - $now->getTimestamp();
        $this->SetTimerInterval('DailyScanTimer', $interval * 1000);

        // Run initial scan & message registration
        $this->UpdateMonitoredDevices();

        $this->DA_SetAvailable(true);
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
            "caption": "ZusÃƒÆ’Ã‚Â¤tzliche / Manuelle Variablen",
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
            "caption": "Jetzt alle Batterien & GerÃƒÆ’Ã‚Â¤te scannen",
            "onClick": "SDM_UpdateMonitoredDevices($id);"
        }
    ]
}
EOT;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            // Variable value changed -> recheck status
            $this->CheckHealth(true);
        }
    }

    /**
     * Scans the system for battery & offline variables and registers VM_UPDATE listeners.
     */
    public function UpdateMonitoredDevices(): void
    {
        $this->SendDebug('Info', 'Starte automatischen Scan nach Batterien und Offline-GerÃƒÆ’Ã‚Â¤ten...', 0);

        // Reset timer interval back to 24h after trigger
        $this->SetTimerInterval('DailyScanTimer', 86400 * 1000);

        $monitoredVars = [];

        // 1. Scan all variables in Symcon
        $varIDs = IPS_GetVariableList();
        foreach ($varIDs as $vid) {
            $obj = IPS_GetObject($vid);
            $ident = strtoupper($obj['ObjectIdent']);
            $var = IPS_GetVariable($vid);
            $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];

            // A) Battery Ident Check
            if (in_array($ident, ['LOWBAT', 'LOW_BAT', 'BATTERY', 'BATTERY_STATE', 'BATTERY_LEVEL', 'BATTERIE', 'OPERATINGVOLTAGE'], true)) {
                $monitoredVars[] = $vid;
                continue;
            }

            // B) Battery Profile Check
            if ($profile !== '' && (stripos($profile, 'battery') !== false || stripos($profile, 'batterie') !== false)) {
                $monitoredVars[] = $vid;
                continue;
            }

            // C) Reachability / Availability Idents
            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH', 'DEVICEAVAILABLE', 'OFFLINE'], true)) {
                $monitoredVars[] = $vid;
                continue;
            }
        }

        // 2. Add custom manually configured variables
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

        // Register VM_UPDATE for each found variable
        foreach ($monitoredVars as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        $this->SendDebug('Info', count($monitoredVars) . ' Variablen zur ÃƒÆ’Ã…â€œberwachung registriert.', 0);

        // Initial Health Check
        $this->CheckHealth(false);
    }

    /**
     * Checks all monitored variables and updates states/notifications.
     */
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

            // Battery check
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

            // Offline check
            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH'], true) && $val === true) {
                $offlineDevices[] = "$deviceName ($varName)";
                $statusText = 'OFFLINE';
                $statusColor = '#FF9900';
            } elseif (in_array($ident, ['DEVICEAVAILABLE', 'OFFLINE'], true) && $val === false) {
                $offlineDevices[] = "$deviceName (Offline)";
                $statusText = 'OFFLINE';
                $statusColor = '#FF9900';
            }

            // Build HTML table row for visibility
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
            $summary[] = "Offline GerÃƒÆ’Ã‚Â¤te ($offCount): " . implode(', ', $offlineDevices);
        }

        $text = count($summary) > 0 ? implode(' | ', $summary) : 'Alle GerÃƒÆ’Ã‚Â¤te betriebsbereit.';
        $this->SetValue('SummaryText', $text);

        // Build HTML Overview
        $html = "<table style='width:100%; border-collapse:collapse;'>";
        $html .= "<tr style='text-align:left;'><th>GerÃƒÆ’Ã‚Â¤t</th><th>Variable</th><th>Status</th></tr>";
        if (count($htmlRows) > 0) {
            $html .= implode('', $htmlRows);
        } else {
            $html .= "<tr><td colspan='3' style='color:#00FF00;'>Alle ÃƒÆ’Ã‚Â¼berwachten GerÃƒÆ’Ã‚Â¤te sind OK</td></tr>";
        }
        $html .= "</table>";
        $this->SetValue('MonitoredListHTML', $html);

        // Push via SmartNotifier if issues detected and notification requested
        if ($triggerNotification && (count($lowBatteries) > 0 || count($offlineDevices) > 0)) {
            $notifierId = $this->ReadPropertyInteger('TargetNotifier');
            if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                $payload = json_encode([
                    'Title' => 'GerÃƒÆ’Ã‚Â¤teÃƒÆ’Ã‚Â¼berwachung',
                    'Message' => $text,
                    'Priority' => 1 // Warning
                ]);
                @IPS_RunScriptText("NOTIFY_SendEvent($notifierId, " . var_export($payload, true) . ");");
            }
        }
    }
}