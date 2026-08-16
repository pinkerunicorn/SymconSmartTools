<?php

declare(strict_types=1);

class VestaboardGenerator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DefaultText', 'Hello World');
        $this->RequireParent('{6EACDB31-15B1-4043-98D7-1750239A060A}');

        $this->RegisterVariableString('Message', 'Nachricht', '', 1);
        $this->EnableAction('Message');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Message') {
            $this->SetValue($Ident, $Value);
            $this->SendTextToBoard((string)$Value);
        }
    }

    public function SendTextToBoard(string $text)
    {
        // Vestaboard array is 6 rows by 22 columns
        $layout = array_fill(0, 6, array_fill(0, 22, 0));
        
        // TODO: Implement actual character mapping logic
        // This is a placeholder array containing only blanks (0)
        
        $this->SendDataToParent(json_encode([
            'DataID' => '{2579048E-50DD-4B1C-B7D9-11B3ADFA53DD}',
            'Command' => 'UpdateLayout',
            'Layout' => $layout
        ]));
    }
    
    public function SendLayout(array $layout)
    {
        $this->SendDataToParent(json_encode([
            'DataID' => '{2579048E-50DD-4B1C-B7D9-11B3ADFA53DD}',
            'Command' => 'UpdateLayout',
            'Layout' => $layout
        ]));
    }
}
