<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';
require_once __DIR__ . '/libs/Trait_GoogleOAuth.php';
require_once __DIR__ . '/libs/Trait_GoogleSync.php';
require_once __DIR__ . '/libs/Trait_GoogleQuery.php';
require_once __DIR__ . '/libs/Trait_GoogleExecute.php';
require_once __DIR__ . '/libs/Trait_ReportState.php';

/**
 * GoogleHomeGateway — Moderne Google Home Cloud-to-Cloud Integration für IP-Symcon.
 *
 * Ein einziges Modul, das alle Geräte als zentrale Konfigurationstabelle verwaltet.
 * Kein separates Device-Modul nötig. Kompatibel mit Symcon Connect (jpmagic.de).
 *
 * Setup:
 *   1. Google Home Developer Console: Neues Cloud-to-Cloud Projekt anlegen
 *   2. Client ID + Secret + Home Graph API Key hier eintragen
 *   3. Fulfillment URL in Google Console: <ConnectURL>/hook/GoogleHomeGateway/fulfillment
 *   4. Auth/Token URL in Google Console: <ConnectURL>/hook/GoogleHomeGateway/auth|token
 *   5. Geräte über "Suche nach Geräten" oder manuell hinzufügen
 *   6. GHGW_RequestSync() einmalig aufrufen
 */
class GoogleHomeGateway extends IPSModuleStrict
{
    use SmartLog_Trait;
    use SmartHttp_Trait;
    use GoogleOAuth_Trait;
    use GoogleSync_Trait;
    use GoogleQuery_Trait;
    use GoogleExecute_Trait;
    use ReportState_Trait;

    // Interne Geräte-Typen
    public const TYPE_SWITCH      = 0;
    public const TYPE_OUTLET      = 1;
    public const TYPE_LIGHT_ONOFF = 2;
    public const TYPE_LIGHT_DIM   = 3;
    public const TYPE_LIGHT_COLOR = 4;
    public const TYPE_BLIND       = 5;

    public const DEVICE_TYPE_LABELS = [
        self::TYPE_SWITCH      => 'Schalter',
        self::TYPE_OUTLET      => 'Steckdose',
        self::TYPE_LIGHT_ONOFF => 'Licht (Schalter)',
        self::TYPE_LIGHT_DIM   => 'Licht (Dimmer)',
        self::TYPE_LIGHT_COLOR => 'Licht (Farbe / CCT)',
        self::TYPE_BLIND       => 'Jalousie / Rolllade',
    ];

    // Google Action Device Types
    public const GOOGLE_TYPES = [
        self::TYPE_SWITCH      => 'action.devices.types.SWITCH',
        self::TYPE_OUTLET      => 'action.devices.types.OUTLET',
        self::TYPE_LIGHT_ONOFF => 'action.devices.types.LIGHT',
        self::TYPE_LIGHT_DIM   => 'action.devices.types.LIGHT',
        self::TYPE_LIGHT_COLOR => 'action.devices.types.LIGHT',
        self::TYPE_BLIND       => 'action.devices.types.BLINDS',
    ];

    // Hook-Pfad-Basis
    private const HOOK_BASE = '/hook/GoogleHomeGateway';

    public function Create(): void
    {
        parent::Create();

        // OAuth2 / Google Console
        $this->RegisterPropertyString('GoogleClientID', '');
        $this->RegisterPropertyString('GoogleClientSecret', '');
        $this->RegisterPropertyString('HomeGraphAPIKey', '');
        $this->RegisterPropertyString('PinCode', '');

        // Geräteliste als JSON (zentrale Konfiguration)
        $this->RegisterPropertyString('Devices', '[]');

        // Internes Attribut: OAuth Tokens
        $this->RegisterAttributeString('OAuthTokens', '{}');
        $this->RegisterAttributeString('OAuthCodes', '{}');

        // Status-Variablen
        $statusIntervals = json_encode([
            ['IntervalMinValue' => 0, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Nicht konfiguriert', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 1, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Online', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Ok', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 2, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'Fehler', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Alert', 'ColorActive' => true, 'ColorValue' => 0xFF4400, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
        ]);

        $this->RegisterVariableInteger('GatewayStatus', 'Gateway Status', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Network',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'      => $statusIntervals,
        ], 1);

        $this->RegisterVariableString('LastSync', 'Letzte Synchronisation', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock',
        ], 2);

        $this->RegisterVariableString('LastExecute', 'Letzter Befehl', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Gear',
        ], 3);

        $this->RegisterVariableInteger('ConnectedDevices', 'Verbundene Geraete', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Network',
        ], 4);

        $this->RegisterVariableString('FulfillmentURL', 'Fulfillment URL', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information',
        ], 5);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->GH_RegisterHook(self::HOOK_BASE . '/fulfillment');
        $this->GH_RegisterHook(self::HOOK_BASE . '/auth');
        $this->GH_RegisterHook(self::HOOK_BASE . '/token');

        // Variablen-Watcher auf alle konfigurierten Variablen registrieren
        $devices = $this->GetDevices();
        foreach ($devices as $device) {
            foreach (['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID'] as $field) {
                $varId = (int)($device[$field] ?? 0);
                if ($varId > 0 && IPS_ObjectExists($varId)) {
                    $this->RegisterReference($varId);
                }
            }
        }

        // Öffentliche URL bestimmen und anzeigen
        $url = $this->GetPublicBaseURL();
        $this->SetValue('FulfillmentURL', $url ? $url . self::HOOK_BASE . '/fulfillment' : 'Symcon Connect nicht verfuegbar');

        // Status setzen
        $clientId = $this->ReadPropertyString('GoogleClientID');
        $pin      = $this->ReadPropertyString('PinCode');
        if (empty($clientId) || empty($pin)) {
            $this->SetValue('GatewayStatus', 0);
            $this->SetStatus(104);
        } else {
            $this->SetValue('GatewayStatus', 1);
            $this->SetStatus(102);
            $this->SetValue('ConnectedDevices', count($devices));
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Hook-Dispatcher
    // ─────────────────────────────────────────────────────────────

    public function ProcessHookData(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $this->SendDebug('Hook', 'URI: ' . $uri, 0);

        if (str_ends_with($uri, '/auth') || str_contains($uri, '/auth?')) {
            $this->HandleAuthRequest();
            return;
        }

        if (str_ends_with($uri, '/token')) {
            $this->HandleTokenRequest();
            return;
        }

        if (str_ends_with($uri, '/fulfillment')) {
            $this->HandleFulfillmentRequest();
            return;
        }

        http_response_code(404);
        echo 'Not found';
    }

    // ─────────────────────────────────────────────────────────────
    // Fulfillment Router (SYNC / QUERY / EXECUTE)
    // ─────────────────────────────────────────────────────────────

    private function HandleFulfillmentRequest(): void
    {
        header('Content-Type: application/json');

        // Token validieren
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$this->ValidateAccessToken($authHeader)) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            return;
        }

        $payload = file_get_contents('php://input');
        $this->SendDebug('Fulfillment', $payload, 0);
        $request = json_decode($payload, true);

        if (!is_array($request) || !isset($request['inputs'][0]['intent'])) {
            http_response_code(400);
            echo json_encode(['error' => 'bad request']);
            return;
        }

        $intent    = $request['inputs'][0]['intent'];
        $requestId = $request['requestId'] ?? uniqid('', true);

        $response = match ($intent) {
            'action.devices.SYNC'    => $this->HandleSync($requestId),
            'action.devices.QUERY'   => $this->HandleQuery($requestId, $request['inputs'][0]['payload']['devices'] ?? []),
            'action.devices.EXECUTE' => $this->HandleExecute($requestId, $request['inputs'][0]['payload']['commands'] ?? []),
            default                  => ['requestId' => $requestId, 'payload' => ['errorCode' => 'notSupported']],
        };

        echo json_encode($response);
    }

    // ─────────────────────────────────────────────────────────────
    // Öffentliche Funktionen
    // ─────────────────────────────────────────────────────────────

    /**
     * Fordert Google auf, einen neuen SYNC durchzuführen (alle Geräte neu einlesen).
     * Aufrufen nach Änderungen an der Geräteliste.
     */
    public function RequestSync(): bool
    {
        $apiKey = $this->ReadPropertyString('HomeGraphAPIKey');
        if (empty($apiKey)) {
            $this->SLogError('RequestSync: Kein Home Graph API Key konfiguriert');
            return false;
        }

        $agentUserId = $this->GetAgentUserId();
        $result = $this->HttpRequest(
            'https://homegraph.googleapis.com/v1/devices:requestSync?key=' . urlencode($apiKey),
            'POST',
            ['Content-Type: application/json'],
            ['agentUserId' => $agentUserId]
        );

        if ($result !== null) {
            $this->SetValue('LastSync', date('d.m.Y H:i:s'));
            $this->SLogInfo('RequestSync erfolgreich');
            return true;
        }

        $this->SetValue('GatewayStatus', 2);
        $this->SLogError('RequestSync fehlgeschlagen');
        return false;
    }

    /**
     * Pusht den aktuellen Zustand aller Geräte an Google (Report State).
     */
    public function ReportAllStates(): void
    {
        $devices = $this->GetDevices();
        $states  = [];
        foreach ($devices as $device) {
            $id          = (string)$device['id'];
            $states[$id] = $this->BuildDeviceState($device);
        }

        if (!empty($states)) {
            $this->PushReportState($states);
        }
    }

    /**
     * Report State für ein einzelnes Gerät (via Variablen-ID getriggert).
     */
    public function ReportStateForVariable(int $varId): void
    {
        $devices = $this->GetDevices();
        foreach ($devices as $device) {
            foreach (['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID'] as $field) {
                if ((int)($device[$field] ?? 0) === $varId) {
                    $id = (string)$device['id'];
                    $this->PushReportState([$id => $this->BuildDeviceState($device)]);
                    return;
                }
            }
        }
    }

    /**
     * Gibt die aktuelle öffentliche Fulfillment-URL zurück.
     */
    public function GetFulfillmentURL(): string
    {
        $base = $this->GetPublicBaseURL();
        return $base ? $base . self::HOOK_BASE . '/fulfillment' : '';
    }

    /**
     * Gerätekonfiguration aus Property laden.
     * Jedes Device: ['id', 'name', 'type', 'room', 'OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID']
     */
    public function GetDevices(): array
    {
        $json = $this->ReadPropertyString('Devices');
        $list = json_decode($json, true);
        return is_array($list) ? $list : [];
    }

    // ─────────────────────────────────────────────────────────────
    // Konfigurator: Geräteerkennung
    // ─────────────────────────────────────────────────────────────

    /**
     * Durchsucht den Symcon-Objektbaum nach bekannten Gerätetypen.
     * Liefert Vorschlagsliste für die Konfigurationsoberfläche.
     */
    public function SearchDevices(): string
    {
        $found = [];

        // Alle Instanzen durchsuchen
        $allInstances = IPS_GetInstanceList();
        foreach ($allInstances as $instanceId) {
            $obj    = IPS_GetObject($instanceId);
            $module = IPS_GetModule(IPS_GetInstance($instanceId)['ModuleID']);

            // Kind-Variablen prüfen
            $children = IPS_GetChildrenIDs($instanceId);
            $vars     = [];
            foreach ($children as $childId) {
                $childObj = IPS_GetObject($childId);
                if ($childObj['ObjectType'] === 2) { // Variable
                    $vars[$childObj['ObjectIdent']] = $childId;
                }
            }

            // Heuristik: Bekannte Identifier erkennen
            $suggestion = $this->GuessDeviceType($vars, $obj['ObjectName'], $module['ModuleName'] ?? '');
            if ($suggestion !== null) {
                $found[] = array_merge($suggestion, [
                    'instanceId' => $instanceId,
                    'name'       => $obj['ObjectName'],
                ]);
            }
        }

        return json_encode($found);
    }

    /**
     * Versucht den Gerätetyp anhand der Variablen-Idents zu erkennen.
     */
    private function GuessDeviceType(array $vars, string $name, string $moduleName): ?array
    {
        $hasOnOff      = isset($vars['STATE']) || isset($vars['LEVEL']) || isset($vars['ON_OFF']);
        $hasBrightness = isset($vars['LEVEL']) || isset($vars['BRIGHTNESS']) || isset($vars['DIM_LEVEL']);
        $hasColor      = isset($vars['COLOR']) || isset($vars['RGB']) || isset($vars['HUE']);
        $hasLevel      = isset($vars['LEVEL']);

        // Homematic Dimmer
        if ($hasBrightness && str_contains($moduleName, 'HM')) {
            return [
                'type'            => self::TYPE_LIGHT_DIM,
                'OnOff_VarID'     => $vars['STATE'] ?? ($vars['LEVEL'] ?? 0),
                'Brightness_VarID'=> $vars['LEVEL'] ?? 0,
            ];
        }

        // Homematic Rolllade
        if ($hasLevel && str_contains(strtolower($name), 'rolllade') || str_contains(strtolower($name), 'jalousie') || str_contains(strtolower($name), 'rollo')) {
            return [
                'type'            => self::TYPE_BLIND,
                'OpenClose_VarID' => $vars['LEVEL'] ?? 0,
            ];
        }

        // Generischer Schalter
        if ($hasOnOff && !$hasBrightness) {
            return [
                'type'        => self::TYPE_SWITCH,
                'OnOff_VarID' => $vars['STATE'] ?? 0,
            ];
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ─────────────────────────────────────────────────────────────

    public function GetPublicBaseURL(): string
    {
        // Symcon Connect URL automatisch ermitteln
        $connectIds = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930D26D80F}');
        if (!empty($connectIds)) {
            $url = CC_GetConnectURL($connectIds[0]);
            if (!empty($url)) {
                return rtrim($url, '/');
            }
        }
        return '';
    }

    public function GetAgentUserId(): string
    {
        return 'symcon-' . $this->InstanceID;
    }

    // ─────────────────────────────────────────────────────────────
    // Hook-Registrierung (analog TedeeLock)
    // ─────────────────────────────────────────────────────────────

    private function GH_RegisterHook(string $hookPath): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (empty($ids)) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        $found = false;
        foreach ($hooks as $index => $hook) {
            if ($hook['Hook'] === $hookPath) {
                if ($hook['TargetID'] !== $this->InstanceID) {
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        }
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }
}
