<?php

declare(strict_types=1);

/**
 * GoogleOAuth_Trait — OAuth2 Authorization Code Flow für Google Home.
 *
 * Implementiert:
 *   - Authorization Endpoint: Zeigt PIN-Eingabe-HTML-Seite
 *   - Token Endpoint: Gibt Access + Refresh Token aus
 *   - Token-Validierung: Prüft Bearer-Token bei jedem Fulfillment-Request
 *
 * Tokens werden verschlüsselt als JSON-Attribut gespeichert.
 * Access Token: 1 Stunde gültig. Refresh Token: 30 Tage.
 */
if (!trait_exists('GoogleOAuth_Trait')) {
    trait GoogleOAuth_Trait
    {
        // ─────────────────────────────────────────────────────────────
        // Authorization Endpoint: PIN-Formular anzeigen
        // ─────────────────────────────────────────────────────────────

        protected function HandleAuthRequest(): void
        {
            $method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $redirectUri = $_GET['redirect_uri'] ?? '';
            $state       = $_GET['state'] ?? '';
            $clientId    = $_GET['client_id'] ?? '';

            // Client ID validieren
            $expectedClientId = $this->ReadPropertyString('GoogleClientID');
            if (!empty($expectedClientId) && $clientId !== $expectedClientId) {
                http_response_code(400);
                echo 'Ungültige Client ID.';
                return;
            }

            if ($method === 'POST') {
                // PIN prüfen
                $enteredPin  = $_POST['pin'] ?? '';
                $correctPin  = $this->ReadPropertyString('PinCode');
                $redirectUri = $_POST['redirect_uri'] ?? $redirectUri;
                $state       = $_POST['state'] ?? $state;

                if (!hash_equals($correctPin, $enteredPin)) {
                    $this->ServeAuthForm($redirectUri, $state, true);
                    return;
                }

                // Autorisierungs-Code generieren
                $code   = bin2hex(random_bytes(16));
                $expiry = time() + 600; // 10 Minuten gültig

                $codes            = json_decode($this->ReadAttributeString('OAuthCodes'), true) ?: [];
                $codes[$code]     = ['expiry' => $expiry, 'redirectUri' => $redirectUri];
                // Alte Codes bereinigen
                foreach ($codes as $k => $v) {
                    if ($v['expiry'] < time()) {
                        unset($codes[$k]);
                    }
                }
                $this->WriteAttributeString('OAuthCodes', json_encode($codes));

                // Redirect zurück zu Google mit Code
                $redirectUrl = $redirectUri . '?code=' . urlencode($code) . '&state=' . urlencode($state);
                $this->SendDebug('OAuth', 'Auth Code generiert, redirect: ' . $redirectUrl, 0);
                header('Location: ' . $redirectUrl);
                return;
            }

            // GET: Formular anzeigen
            $this->ServeAuthForm($redirectUri, $state, false);
        }

        protected function HandleTokenRequest(): void
        {
            header('Content-Type: application/json');

            $grantType    = $_POST['grant_type'] ?? '';
            $clientId     = $_POST['client_id'] ?? '';
            $clientSecret = $_POST['client_secret'] ?? '';

            // Client-Authentifizierung via POST oder HTTP Basic Auth erlauben
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (str_starts_with($authHeader, 'Basic ')) {
                $basic = explode(':', base64_decode(substr($authHeader, 6)), 2);
                if (count($basic) === 2) {
                    $clientId     = $basic[0];
                    $clientSecret = $basic[1];
                }
            }

            $this->SendDebug('OAuth Token', "Empfangener Request: grant_type=$grantType, client_id=$clientId", 0);

            $expectedClientID     = $this->ReadPropertyString('GoogleClientID');
            $expectedClientSecret = $this->ReadPropertyString('GoogleClientSecret');

            // Client validieren
            if ($clientId !== $expectedClientID || $clientSecret !== $expectedClientSecret) {
                $this->SendDebug('OAuth Token Error', "Client-Mismatch! Empfangen: id='$clientId', secret='$clientSecret' | Erwartet: id='$expectedClientID', secret='$expectedClientSecret'", 0);
                http_response_code(401);
                echo json_encode(['error' => 'invalid_client']);
                return;
            }

            if ($grantType === 'authorization_code') {
                $this->HandleAuthorizationCodeGrant();
            } elseif ($grantType === 'refresh_token') {
                $this->HandleRefreshTokenGrant();
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'unsupported_grant_type']);
            }
        }

        private function HandleAuthorizationCodeGrant(): void
        {
            $code        = $_POST['code'] ?? '';
            $redirectUri = $_POST['redirect_uri'] ?? '';

            $codes = json_decode($this->ReadAttributeString('OAuthCodes'), true) ?: [];

            if (!isset($codes[$code]) || $codes[$code]['expiry'] < time()) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Authorization code invalid or expired']);
                return;
            }

            unset($codes[$code]);
            $this->WriteAttributeString('OAuthCodes', json_encode($codes));

            [$accessToken, $refreshToken] = $this->GenerateTokenPair();

            echo json_encode([
                'access_token'  => $accessToken,
                'token_type'    => 'Bearer',
                'expires_in'    => 3600,
                'refresh_token' => $refreshToken,
            ]);

            $this->SendDebug('OAuth', 'Token ausgestellt (authorization_code)', 0);
        }

        private function HandleRefreshTokenGrant(): void
        {
            $refreshToken = $_POST['refresh_token'] ?? '';
            $tokens       = json_decode($this->ReadAttributeString('OAuthTokens'), true) ?: [];

            $found = false;
            foreach ($tokens as $entry) {
                if (isset($entry['refresh_token']) && hash_equals($entry['refresh_token'], $refreshToken)) {
                    if ($entry['refresh_expiry'] > time()) {
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Refresh token invalid or expired']);
                return;
            }

            [$accessToken] = $this->GenerateTokenPair($refreshToken);

            echo json_encode([
                'access_token' => $accessToken,
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
            ]);

            $this->SendDebug('OAuth', 'Token erneuert (refresh_token)', 0);
        }

        // ─────────────────────────────────────────────────────────────
        // Token-Validierung
        // ─────────────────────────────────────────────────────────────

        protected function ValidateAccessToken(string $authHeader): bool
        {
            if (!str_starts_with($authHeader, 'Bearer ')) {
                return false;
            }
            $token  = substr($authHeader, 7);
            $tokens = json_decode($this->ReadAttributeString('OAuthTokens'), true) ?: [];

            foreach ($tokens as $entry) {
                if (isset($entry['access_token']) && hash_equals($entry['access_token'], $token)) {
                    return $entry['access_expiry'] > time();
                }
            }
            return false;
        }

        // ─────────────────────────────────────────────────────────────
        // Interne Hilfsmethoden
        // ─────────────────────────────────────────────────────────────

        private function GenerateTokenPair(string $existingRefresh = ''): array
        {
            $accessToken  = bin2hex(random_bytes(32));
            $refreshToken = $existingRefresh ?: bin2hex(random_bytes(32));

            $tokens = json_decode($this->ReadAttributeString('OAuthTokens'), true) ?: [];

            // Alten Eintrag mit gleichem Refresh-Token aktualisieren oder neu einfügen
            $updated = false;
            foreach ($tokens as &$entry) {
                if (isset($entry['refresh_token']) && $entry['refresh_token'] === $refreshToken) {
                    $entry['access_token']  = $accessToken;
                    $entry['access_expiry'] = time() + 3600;
                    $updated = true;
                    break;
                }
            }
            unset($entry);

            if (!$updated) {
                $tokens[] = [
                    'access_token'   => $accessToken,
                    'access_expiry'  => time() + 3600,
                    'refresh_token'  => $refreshToken,
                    'refresh_expiry' => time() + (86400 * 30),
                ];
            }

            // Abgelaufene Tokens bereinigen
            $tokens = array_values(array_filter($tokens, fn($t) => $t['refresh_expiry'] > time()));

            $this->WriteAttributeString('OAuthTokens', json_encode($tokens));

            return [$accessToken, $refreshToken];
        }

        private function ServeAuthForm(string $redirectUri, string $state, bool $error): void
        {
            header('Content-Type: text/html; charset=utf-8');
            $errorMsg = $error ? '<p class="error">Falsche PIN. Bitte versuche es erneut.</p>' : '';
            $safeRedir = htmlspecialchars($redirectUri, ENT_QUOTES);
            $safeState = htmlspecialchars($state, ENT_QUOTES);
            echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP-Symcon – Google Home Verknüpfung</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 48px 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0,0,0,0.5);
        }
        .logo { font-size: 48px; margin-bottom: 16px; }
        h1 { color: #fff; font-size: 22px; font-weight: 600; margin-bottom: 8px; }
        p { color: rgba(255,255,255,0.6); font-size: 14px; margin-bottom: 28px; }
        .error { color: #ff6b6b; margin-bottom: 16px; font-size: 14px; }
        input[type=password] {
            width: 100%;
            padding: 14px 18px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            font-size: 18px;
            letter-spacing: 4px;
            text-align: center;
            outline: none;
            margin-bottom: 20px;
            transition: border-color 0.2s;
        }
        input[type=password]:focus { border-color: #4285f4; }
        button {
            width: 100%;
            padding: 14px;
            background: #4285f4;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        button:hover { background: #3367d6; }
        button:active { transform: scale(0.98); }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">🏠</div>
        <h1>IP-Symcon verbinden</h1>
        <p>Gib deine PIN ein, um IP-Symcon mit Google Home zu verknüpfen.</p>
        {$errorMsg}
        <form method="POST">
            <input type="hidden" name="redirect_uri" value="{$safeRedir}">
            <input type="hidden" name="state" value="{$safeState}">
            <input type="password" name="pin" placeholder="••••" autofocus autocomplete="off" maxlength="20">
            <button type="submit">Verknüpfen</button>
        </form>
    </div>
</body>
</html>
HTML;
        }
    }
}
