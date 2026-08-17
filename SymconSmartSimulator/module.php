<?php

declare(strict_types=1);

class SymconSmartSimulator extends IPSModuleStrict
{
    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Variablen registrieren
        $this->RegisterVariableBoolean('TestWindow', 'Test-Fenster', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'window-maximize'
        ], 10);
        $this->EnableAction('TestWindow');

        $this->RegisterVariableBoolean('TestLeakage', 'Test-Wassersensor', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'water'
        ], 11);
        $this->EnableAction('TestLeakage');

        $this->RegisterVariableBoolean('TestMotion', 'Test-Bewegungsmelder', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'person-running'
        ], 12);
        $this->EnableAction('TestMotion');

        $this->RegisterVariableBoolean('TestLowBat', 'Test-Batteriestatus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'battery-full'
        ], 13);
        $this->EnableAction('TestLowBat');

        $this->RegisterVariableBoolean('TestSmoke', 'Test-Rauchmelder', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'smog'
        ], 14);
        $this->EnableAction('TestSmoke');

        $this->RegisterVariableBoolean('TestAvailability', 'Test-Verfügbarkeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'network-wired'
        ], 15);
        $this->EnableAction('TestAvailability');
    }

    public function Destroy(): void
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $profilesToDelete = [
            'Simulator.Window', 'Simulator.Leakage', 'Simulator.Motion',
            'Simulator.LowBat', 'Simulator.Smoke', 'Simulator.Availability'
        ];
        foreach ($profilesToDelete as $profile) {
            if (IPS_VariableProfileExists($profile)) {
                IPS_DeleteVariableProfile($profile);
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TestWindow':
            case 'TestLeakage':
            case 'TestMotion':
            case 'TestLowBat':
            case 'TestSmoke':
            case 'TestAvailability':
                $this->SetValue($Ident, $Value);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }
}
