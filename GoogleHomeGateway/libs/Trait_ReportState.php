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
         * Erzeugt ein OAuth 2.0 Access Token aus einer Service Account JSON.
         */
        private function GetGoogleAccessToken(string $jsonKey): ?string
        {
            $key = json_decode($jsonKey, true);
            if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
                $this->SLogWarning('ReportState', 'Service Account JSON ist ungueltig oder leer.');
                return null;
            }

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $now = time();
            $payload = json_encode([
                'iss'   => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/homegraph',
                'aud'   => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'exp'   => $now + 3600,
                'iat'   => $now
            ]);

            $base64UrlEncode = function ($data) {
                return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
            };

            $jwt = $base64UrlEncode($header) . '.' . $base64UrlEncode($payload);

            if (!openssl_sign($jwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
                $this->SLogWarning('ReportState', 'Signieren des JWT fehlgeschlagen (OpenSSL Error).');
                return null;
            }

            $jwt .= '.' . $base64UrlEncode($signature);

            $ch = curl_init($key['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt
                ]),
                CURLOPT_TIMEOUT        => 5,
            ]);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($res === false || $httpCode >= 400) {
                $this->SLogWarning('ReportState', "Token-Abruf fehlgeschlagen. HTTP $httpCode | $res");
                return null;
            }

            $data = json_decode((string)$res, true);
            return $data['access_token'] ?? null;
        }

        /**
         * Pusht den Zustand einer oder mehrerer Geräte an die Google Home Graph API.
         *
         * @param array $deviceStates ['deviceId' => ['on' => true, ...], ...]
         */
        protected function PushReportState(array $deviceStates): bool
        {
            $serviceAccountJson = $this->ReadPropertyString('ServiceAccountJSON');
            if (empty($serviceAccountJson)) {
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

            // Access Token holen
            $accessToken = $this->GetGoogleAccessToken($serviceAccountJson);
            if (!$accessToken) {
                // Fallback: Versuche es als reinen API Key (wird bei Google vermutlich 403 liefern,
                // aber fängt den Fall ab, falls jemand tatsächlich noch einen funktionierenden Legacy-Key hat)
                $url = 'https://homegraph.googleapis.com/v1/devices:reportStateAndNotification?key=' . urlencode($serviceAccountJson);
                $headers = ['Content-Type: application/json'];
            } else {
                $url = 'https://homegraph.googleapis.com/v1/devices:reportStateAndNotification';
                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken
                ];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 5,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->SLogWarning('ReportState fehlgeschlagen', "cURL Error: $error");
                return false;
            }

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
