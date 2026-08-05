<?php

declare(strict_types=1);

/**
 * ReportState_Trait — Proaktiver Push an die Google Home Graph API.
 *
 * Sendet Gerätezustände an Google, sobald sich Symcon-Variablen ändern.
 * Google Home App wird so live aktualisiert, ohne dass Google erst fragen muss.
 *
 * Voraussetzung: Home Graph API Key im Gateway konfiguriert.
 */
if (!trait_exists('ReportState_Trait')) {
    trait ReportState_Trait
    {
        /**
         * Pusht den Zustand einer oder mehrerer Geräte an die Google Home Graph API.
         *
         * @param array $deviceStates ['deviceId' => ['on' => true, ...], ...]
         */
        protected function PushReportState(array $deviceStates): bool
        {
            $apiKey = $this->ReadPropertyString('HomeGraphAPIKey');
            if (empty($apiKey)) {
                return false;
            }

            $agentUserId = $this->GetAgentUserId();
            $requestId   = uniqid('rs-', true);

            $payload = [
                'requestId'   => $requestId,
                'agentUserId' => $agentUserId,
                'payload'     => [
                    'devices' => [
                        'states' => $deviceStates,
                    ],
                ],
            ];

            $this->SendDebug('ReportState', json_encode($payload), 0);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://homegraph.googleapis.com/v1/devices:reportStateAndNotification?key=' . urlencode($apiKey),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 5,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->SendDebug('ReportState', 'Erfolg HTTP ' . $httpCode, 0);
                return true;
            }

            $this->SLogWarning(
                'ReportState fehlgeschlagen',
                "HTTP $httpCode | cURL: $error | Response: $response"
            );
            return false;
        }
    }
}
