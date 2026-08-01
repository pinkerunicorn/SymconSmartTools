<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

/**
 * SmartDeviceMonitor
 * Vollautomatischer Monitor für Batteriestände, Gerätestatus (Offline) und Updates.
 *
 * @author Florian Graßinger
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

        $this->RegisterVariableInteger('OfflineDeviceCount', 'Offline Geräte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'alert-triangle'
        ], 2);

        $this->RegisterVariableString('SummaryText', 'Status Zusammenfassung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'information'
        ], 100);

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
        $this->SLog('INFO', 'Starte automatischen Scan nach Batterien und Offline-Geräten...');

        // Reset timer interval back to 24h after trigger
        $this->SetTimerInterval('DailyScanTimer', 86400 * 1000);

        $monitoredVars = [];

        // 1. Scan all variables in Symcon
        $varIDs = IPS_GetVariableList();
        foreach ($varIDs as $vid) {
            $obj = IPS_GetObject($vid);
            $ident = strtoupper($obj['ObjectIdent']);

            // Battery Idents
            if (in_array($ident, ['LOWBAT', 'LOW_BAT', 'BATTERY_STATE', 'OPERATINGVOLTAGE'], true)) {
                $monitoredVars[] = $vid;
                continue;
            }

            // Reachability / Availability Idents
            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH', 'DEVICEAVAILABLE'], true)) {
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

        $this->SLog('INFO', count($monitoredVars) . ' Variablen zur Überwachung registriert.');

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

            // Battery check
            if (in_array($ident, ['LOWBAT', 'LOW_BAT'], true) && $val === true) {
                $lowBatteries[] = "$deviceName ($varName)";
            } elseif ($ident === 'OPERATINGVOLTAGE' && is_numeric($val) && $val < $threshold) {
                $lowBatteries[] = "$deviceName ($val V)";
            }

            // Offline check
            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH'], true) && $val === true) {
                $offlineDevices[] = "$deviceName ($varName)";
            } elseif ($ident === 'DEVICEAVAILABLE' && $val === false) {
                $offlineDevices[] = "$deviceName (Offline)";
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

        $text = count($summary) > 0 ? implode(' | ', $summary) : 'Alle Systeme betriebsbereit.';
        $this->SetValue('SummaryText', $text);

        // Push via SmartNotifier if issues detected and notification requested
        if ($triggerNotification && (count($lowBatteries) > 0 || count($offlineDevices) > 0)) {
            $notifierId = $this->ReadPropertyInteger('TargetNotifier');
            if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                $payload = json_encode([
                    'Title' => 'Geräteüberwachung',
                    'Message' => $text,
                    'Priority' => 1 // Warning
                ]);
                @IPS_RunScriptText("NOTIFY_SendEvent($notifierId, " . var_export($payload, true) . ");");
            }
        }
    }
}