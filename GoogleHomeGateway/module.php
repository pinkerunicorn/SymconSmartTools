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
    public const TYPE_THERMOSTAT  = 6;
    public const TYPE_SCENE       = 7;

    public const DEVICE_TYPE_LABELS = [
        self::TYPE_SWITCH      => 'Schalter',
        self::TYPE_OUTLET      => 'Steckdose',
        self::TYPE_LIGHT_ONOFF => 'Licht (Schalter)',
        self::TYPE_LIGHT_DIM   => 'Licht (Dimmer)',
        self::TYPE_LIGHT_COLOR => 'Licht (Farbe / CCT)',
        self::TYPE_BLIND       => 'Jalousie / Rolllade',
        self::TYPE_THERMOSTAT  => 'Thermostat',
        self::TYPE_SCENE       => 'Szene',
    ];

    // Google Action Device Types
    public const GOOGLE_TYPES = [
        self::TYPE_SWITCH      => 'action.devices.types.SWITCH',
        self::TYPE_OUTLET      => 'action.devices.types.OUTLET',
        self::TYPE_LIGHT_ONOFF => 'action.devices.types.LIGHT',
        self::TYPE_LIGHT_DIM   => 'action.devices.types.LIGHT',
        self::TYPE_LIGHT_COLOR => 'action.devices.types.LIGHT',
        self::TYPE_BLIND       => 'action.devices.types.BLINDS',
        self::TYPE_THERMOSTAT  => 'action.devices.types.THERMOSTAT',
        self::TYPE_SCENE       => 'action.devices.types.SCENE',
    ];

    // Hook-Pfad-Basis
    private const HOOK_BASE = '/hook/GoogleHomeGateway';

    public function Create(): void
    {
        parent::Create();

        // OAuth2 / Google Console
        $this->RegisterPropertyString('GoogleClientID', '');
        $this->RegisterPropertyString('GoogleClientSecret', '');
        $this->RegisterPropertyString('ServiceAccountJSON', '');
        $this->RegisterPropertyString('PinCode', '');

        // Device Registry Anbindung
        $this->RegisterPropertyInteger('RegistryID', 0);
        
        // DeviceFilter (Welche Geräte sollen an Google Home gemeldet werden?)
        $this->RegisterPropertyString('DeviceFilter', '[]');

        // Internes Attribut: OAuth Tokens
        $this->RegisterAttributeString('OAuthTokens', '{}');
        $this->RegisterAttributeString('OAuthCodes', '{}');

        // Queue und Timer für Report State Debouncing
        $this->RegisterAttributeString('ReportStateQueue', '[]');
        $this->RegisterTimer('ReportStateTimer', 0, 'GHGW_ProcessReportStateQueue($_IPS[\'TARGET\']);');

        // Status-Variablen
        $statusIntervals = json_encode([
            ['IntervalMinValue' => 0, 'IntervalMaxValue' => 0, 'ConstantActive' => true, 'ConstantValue' => 'Nicht konfiguriert', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0x888888, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 1, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Online', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'circle-check', 'ColorActive' => true, 'ColorValue' => 0x00FF00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 2, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Fehler', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'ColorActive' => true, 'ColorValue' => 0xFF4400, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
        ]);

        $this->RegisterVariableInteger('GatewayStatus', 'Gateway Status', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'network-wired',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'      => $statusIntervals,
        ], 1);

        $this->RegisterVariableString('LastSync', 'Letzte Synchronisation', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'clock-rotate-left',
        ], 2);

        $this->RegisterVariableString('LastExecute', 'Letzter Befehl', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'gear',
        ], 3);

        $this->RegisterVariableInteger('ConnectedDevices', 'Verbundene Geraete', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'network-wired',
        ], 4);

        $this->RegisterVariableString('FulfillmentURL', 'Fulfillment URL', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'circle-info',
        ], 5);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();



        $this->GH_RegisterHook(self::HOOK_BASE . '/fulfillment');
        $this->GH_RegisterHook(self::HOOK_BASE . '/auth');
        $this->GH_RegisterHook(self::HOOK_BASE . '/token');

        // Alle konfigurierten Geräte abrufen (GetDevices liest nun aus der Registry)
        $devices = $this->GetDevices();

        // Variablen-Referenzen und Message-Watcher registrieren
        foreach ($devices as $device) {
            foreach (['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID'] as $field) {
                $varId = (int)($device[$field] ?? 0);
                if ($varId > 0 && IPS_ObjectExists($varId)) {
                    $this->RegisterReference($varId);
                    $this->RegisterMessage($varId, VM_UPDATE);
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


    public function GetConfigurationForm(): string
    {
        $jsonForm = file_get_contents(__DIR__ . '/form.json');
        $form     = json_decode($jsonForm, true);

        if (is_array($form) && isset($form['elements'])) {
            $registryId = $this->ReadPropertyInteger('RegistryID');
            $deviceFilterValues = [];
            
            if ($registryId > 0 && IPS_InstanceExists($registryId)) {
                $mappings = [
                    'DevicesSwitch'      => 'Schalter',
                    'DevicesSocket'      => 'Steckdose',
                    'DevicesLight'       => 'Licht (Schalter)',
                    'DevicesLightDimmer' => 'Licht (Dimmer)',
                    'DevicesLightColor'  => 'Licht (Farbe)',
                    'DevicesBlind'       => 'Jalousie',
                    'DevicesThermostat'  => 'Thermostat',
                    'DevicesScene'       => 'Szene'
                ];
                
                $registryDevices = [];
                foreach ($mappings as $propName => $category) {
                    $json = @IPS_GetProperty($registryId, $propName);
                    if ($json !== false) {
                        $list = json_decode($json, true);
                        if (is_array($list)) {
                            foreach ($list as $dev) {
                                if (isset($dev['id']) && isset($dev['name'])) {
                                    $registryDevices[(string)$dev['id']] = [
                                        'name' => $dev['name'] . (!empty($dev['room']) ? " ({$dev['room']})" : ""),
                                        'type' => $category
                                    ];
                                }
                            }
                        }
                    }
                }

                // Um den berüchtigten Symcon List Index-Merge-Bug zu umgehen, müssen wir die Inject-Werte 
                // EXAKT in der Reihenfolge generieren, in der sie aktuell in der Eigenschaft abgespeichert sind!
                $filterJson = $this->ReadPropertyString('DeviceFilter');
                $filterArr = json_decode($filterJson, true) ?: [];
                
                // 1. Zuerst alle Geräte hinzufügen, die bereits in der Eigenschaft existieren (in identischer Reihenfolge)
                foreach ($filterArr as $f) {
                    if (isset($f['id'])) {
                        $id = (string)$f['id'];
                        if (isset($registryDevices[$id])) {
                            $info = $registryDevices[$id];
                            $deviceFilterValues[] = [
                                'sync' => isset($f['sync']) ? (bool)$f['sync'] : true,
                                'name' => $info['name'],
                                'type' => $info['type'],
                                'id'   => $id
                            ];
                            // Aus Registry-Liste entfernen, damit wir am Ende nur noch neue Geräte haben
                            unset($registryDevices[$id]);
                        }
                    }
                }
                
                // 2. Neue Geräte (die noch nicht gespeichert sind) alphabetisch ans Ende der Liste anfügen
                $newDevices = [];
                foreach ($registryDevices as $id => $info) {
                    $newDevices[] = [
                        'sync' => false,
                        'name' => $info['name'],
                        'type' => $info['type'],
                        'id'   => $id
                    ];
                }
                usort($newDevices, function($a, $b) {
                    $cmp = strcasecmp($a['type'], $b['type']);
                    if ($cmp === 0) {
                        return strcasecmp($a['name'], $b['name']);
                    }
                    return $cmp;
                });
                
                $deviceFilterValues = array_merge($deviceFilterValues, $newDevices);
            }

            foreach ($form['elements'] as &$element) {
                if (($element['type'] ?? '') === 'ExpansionPanel' && ($element['caption'] ?? '') === 'Geräte-Freigabe (Auswahl für Google Home)' && isset($element['items'])) {
                    foreach ($element['items'] as &$item) {
                        if (($item['type'] ?? '') === 'List' && ($item['name'] ?? '') === 'DeviceFilter') {
                            $item['values'] = $deviceFilterValues;
                        }
                    }
                    unset($item);
                }
            }
            unset($element);
        }

        return json_encode($form);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message === VM_UPDATE) {
            $this->SendDebug('MessageSink', "Variable $SenderID hat sich geaendert -> Debounce ReportState", 0);
            $queue = json_decode($this->ReadAttributeString('ReportStateQueue'), true) ?: [];
            if (!in_array($SenderID, $queue, true)) {
                $queue[] = $SenderID;
                $this->WriteAttributeString('ReportStateQueue', json_encode($queue));
            }
            $this->SetTimerInterval('ReportStateTimer', 2000);
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

        $this->SendDebug('FulfillmentResponse', json_encode($response), 0);
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
        $serviceAccountJson = $this->ReadPropertyString('ServiceAccountJSON');
        if (empty($serviceAccountJson)) {
            $this->SendDebug('RequestSync', 'Kein Service Account JSON konfiguriert!', 0);
            $this->SLogError('RequestSync: Kein Service Account JSON konfiguriert');
            echo "Fehler: Kein Service Account JSON in den Instanz-Einstellungen hinterlegt!";
            return false;
        }

        $agentUserId = $this->GetAgentUserId();
        $this->SendDebug('RequestSync', 'Sende RequestSync an Google fuer agentUserId: ' . $agentUserId, 0);

        $accessToken = $this->GetGoogleAccessToken($serviceAccountJson);
        if (!$accessToken) {
            // Fallback für Legacy-Key
            $url = 'https://homegraph.googleapis.com/v1/devices:requestSync?key=' . urlencode($serviceAccountJson);
            $headers = ['Content-Type: application/json'];
        } else {
            $url = 'https://homegraph.googleapis.com/v1/devices:requestSync';
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ];
        }

        $result = $this->HttpRequest(
            $url,
            'POST',
            $headers,
            ['agentUserId' => $agentUserId]
        );

        $this->SendDebug('RequestSync', 'Google Response: ' . json_encode($result), 0);

        if ($result !== null) {
            $this->SetValue('LastSync', date('d.m.Y H:i:s'));
            $this->SLogInfo('RequestSync erfolgreich');
            echo "Google Sync wurde erfolgreich angefordert!";
            return true;
        }

        $this->SetValue('GatewayStatus', 2);
        $this->SLogError('RequestSync fehlgeschlagen');
        echo "Fehler beim Anfordern des Google Syncs. Bitte prüfe das Symcon-Meldungsfenster für Details!";
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
     * Gerätekonfiguration aus allen typspezifischen Properties zusammenführen.
     * Jedes Device erhält seinen Typ implizit anhand der Liste.
     */
    public function GetDevices(): array
    {
        $allDevices = [];
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId <= 0 || !IPS_InstanceExists($registryId)) {
            $this->SendDebug('GetDevices', "RegistryID invalid or missing: $registryId", 0);
            return [];
        }

        $filterJson = $this->ReadPropertyString('DeviceFilter');
        $filterArr = json_decode($filterJson, true) ?: [];
        $filterMap = [];
        foreach ($filterArr as $f) {
            if (isset($f['id'])) {
                $filterMap[(string)$f['id']] = isset($f['sync']) ? (bool)$f['sync'] : true;
            }
        }

        $this->SendDebug('GetDevices', "RegistryID: $registryId", 0);

        $mappings = [
            self::TYPE_SWITCH      => 'DevicesSwitch',
            self::TYPE_OUTLET      => 'DevicesSocket',
            self::TYPE_LIGHT_ONOFF => 'DevicesLight',
            self::TYPE_LIGHT_DIM   => 'DevicesLightDimmer',
            self::TYPE_LIGHT_COLOR => 'DevicesLightColor',
            self::TYPE_BLIND       => 'DevicesBlind',
            self::TYPE_THERMOSTAT  => 'DevicesThermostat',
            self::TYPE_SCENE       => 'DevicesScene',
        ];

        foreach ($mappings as $type => $propName) {
            $json = @IPS_GetProperty($registryId, $propName);
            if ($json === false) {
                $this->SendDebug('GetDevices', "Property $propName could not be read from Registry.", 0);
                continue;
            }
            
            $list = json_decode($json, true);
            if (is_array($list)) {
                $this->SendDebug('GetDevices', "Loaded " . count($list) . " devices for $propName", 0);
                foreach ($list as $dev) {
                    $id = (string)($dev['id'] ?? '');
                    if ($id !== '') {
                        // Default auf false: Neue Geräte müssen erst manuell freigegeben werden
                        $sync = isset($filterMap[$id]) ? $filterMap[$id] : false;
                        if (!$sync) {
                            continue; // Ausgefiltert (Aktiv = false)
                        }
                    }
                    $dev['type'] = $type; // Typ dynamisch einfügen
                    $allDevices[] = $dev;
                }
            }
        }

        $this->SendDebug('GetDevices', "Total Devices to sync: " . count($allDevices), 0);
        return $allDevices;
    }

    // ─────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ─────────────────────────────────────────────────────────────

    public function GetPublicBaseURL(): string
    {
        // Symcon Connect URL automatisch ermitteln
        $connectIds = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
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

    /**
     * Report State für gepufferte Variablen (Debouncing).
     */
    public function ProcessReportStateQueue(): void
    {
        $this->SetTimerInterval('ReportStateTimer', 0);
        $queue = json_decode($this->ReadAttributeString('ReportStateQueue'), true) ?: [];
        $this->WriteAttributeString('ReportStateQueue', '[]');

        if (empty($queue)) {
            return;
        }

        $devices = $this->GetDevices();
        $statesToReport = [];

        foreach ($devices as $device) {
            foreach (['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID'] as $field) {
                $varId = (int)($device[$field] ?? 0);
                if ($varId > 0 && in_array($varId, $queue, true)) {
                    $id = (string)$device['id'];
                    $statesToReport[$id] = $this->BuildDeviceState($device);
                    break;
                }
            }
        }

        if (!empty($statesToReport)) {
            $this->PushReportState($statesToReport);
        }
    }
}
