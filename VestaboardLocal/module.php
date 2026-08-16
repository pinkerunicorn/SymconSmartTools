<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class VestaboardLocal extends IPSModule
{
    use DeviceAvailability_Trait;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('IPAddress', '');
        $this->RegisterPropertyString('LocalToken', '');
        
        $this->DA_RegisterAvailability(900);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        
        if (isset($data['Layout'])) {
            $this->SendLayoutToBoard($data['Layout']);
            return "OK";
        }
        
        return "UNKNOWN_COMMAND";
    }

    private function SendLayoutToBoard(array $layout)
    {
        $ip = $this->ReadPropertyString('IPAddress');
        $token = $this->ReadPropertyString('LocalToken');

        if (empty($ip) || empty($token)) {
            $this->DA_SetAvailable(false, 'Missing IP or Token');
            return;
        }

        $url = "http://{$ip}:7000/local-api/message";
        $headers = [
            'X-Vestaboard-Local-Api-Key: ' . $token,
            'Content-Type: application/json'
        ];

        // Ensure we send exactly what Vestaboard expects
        $body = json_encode($layout);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->DA_SetAvailable(false, curl_error($ch));
        } elseif ($httpCode !== 200) {
            $this->DA_SetAvailable(false, 'HTTP Error ' . $httpCode);
        } else {
            $this->DA_SetAvailable(true);
        }

        curl_close($ch);
    }
}
