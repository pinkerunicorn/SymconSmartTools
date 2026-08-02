<?php

declare(strict_types=1);

class SymconSmartSimulator extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Profil: Fenster
        $this->RegisterProfileBoolean('Simulator.Window', 'Window');
        IPS_SetVariableProfileAssociation('Simulator.Window', false, 'Geschlossen', 'Window', 0x00FF00);
        IPS_SetVariableProfileAssociation('Simulator.Window', true, 'Offen', 'Window', 0xFF0000);

        // Profil: Wassermelder
        $this->RegisterProfileBoolean('Simulator.Leakage', 'Water');
        IPS_SetVariableProfileAssociation('Simulator.Leakage', false, 'Trocken', 'Water', 0x00FF00);
        IPS_SetVariableProfileAssociation('Simulator.Leakage', true, 'Leckage', 'Water', 0xFF0000);

        // Profil: Bewegungsmelder
        $this->RegisterProfileBoolean('Simulator.Motion', 'Motion');
        IPS_SetVariableProfileAssociation('Simulator.Motion', false, 'Ruhig', 'Motion', 0x00FF00);
        IPS_SetVariableProfileAssociation('Simulator.Motion', true, 'Bewegung', 'Motion', 0xFF0000);

        // Profil: Batteriestatus
        $this->RegisterProfileBoolean('Simulator.LowBat', 'Battery');
        IPS_SetVariableProfileAssociation('Simulator.LowBat', false, 'Voll', 'Battery', 0x00FF00);
        IPS_SetVariableProfileAssociation('Simulator.LowBat', true, 'Leer', 'Battery', 0xFF0000);

        // Profil: Rauchmelder
        $this->RegisterProfileBoolean('Simulator.Smoke', 'Flame');
        IPS_SetVariableProfileAssociation('Simulator.Smoke', false, 'Normal', 'Flame', 0x00FF00);
        IPS_SetVariableProfileAssociation('Simulator.Smoke', true, 'Alarm', 'Alert', 0xFF0000);

        // Profil: Geräte-Verfügbarkeit
        $this->RegisterProfileBoolean('Simulator.Availability', 'Network');
        IPS_SetVariableProfileAssociation('Simulator.Availability', false, 'Offline', 'Network', 0xFF0000);
        IPS_SetVariableProfileAssociation('Simulator.Availability', true, 'Online', 'Network', 0x00FF00);

        // Variablen registrieren
        $this->RegisterVariableBoolean('TestWindow', 'Test-Fenster', 'Simulator.Window', 10);
        $this->EnableAction('TestWindow');

        $this->RegisterVariableBoolean('TestLeakage', 'Test-Wassersensor', 'Simulator.Leakage', 11);
        $this->EnableAction('TestLeakage');

        $this->RegisterVariableBoolean('TestMotion', 'Test-Bewegungsmelder', 'Simulator.Motion', 12);
        $this->EnableAction('TestMotion');

        $this->RegisterVariableBoolean('TestLowBat', 'Test-Batteriestatus', 'Simulator.LowBat', 13);
        $this->EnableAction('TestLowBat');

        $this->RegisterVariableBoolean('TestSmoke', 'Test-Rauchmelder', 'Simulator.Smoke', 14);
        $this->EnableAction('TestSmoke');

        $this->RegisterVariableBoolean('TestAvailability', 'Test-Verfügbarkeit', 'Simulator.Availability', 15);
        $this->EnableAction('TestAvailability');
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value)
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

    private function RegisterProfileBoolean(string $Name, string $Icon)
    {
        if (!@IPS_GetVariableProfile($Name)) {
            IPS_CreateVariableProfile($Name, 0); // 0 = Boolean
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 0) {
                throw new Exception('Variable profile type does not match for profile ' . $Name);
            }
        }
        IPS_SetVariableProfileIcon($Name, $Icon);
    }
}
