# Google Home Gateway

Moderne Google Home Cloud-to-Cloud Integration für IP-Symcon 8/9.

## Features

- **SYNC / QUERY / EXECUTE** – vollständige Cloud-to-Cloud Implementierung
- **Report State** – automatischer Live-Push bei Variablenänderungen
- **Zentrale Konfiguration** – alle Geräte in einer einzigen Instanz
- **Symcon Connect** – nutzt automatisch jpmagic.de als HTTPS-Relay
- **PIN-Authentifizierung** – sicherer OAuth2 Flow

## Unterstützte Gerätetypen (Phase 1)

| Typ | Google Type | Traits |
|---|---|---|
| Schalter | SWITCH | OnOff |
| Steckdose | OUTLET | OnOff |
| Licht (Schalter) | LIGHT | OnOff |
| Licht (Dimmer) | LIGHT | OnOff, Brightness |
| Licht (Farbe) | LIGHT | OnOff, Brightness, ColorSetting |
| Jalousie | BLINDS | OpenClose |

## Setup

### 1. Google Home Developer Console

1. https://console.home.google.com/ öffnen
2. Neues Projekt → **Cloud-to-cloud** Integration
3. **Fulfillment URL**: `https://<CONNECT-ID>.jpmagic.de/hook/GoogleHomeGateway/fulfillment`
4. **Authorization URL**: `https://<CONNECT-ID>.jpmagic.de/hook/GoogleHomeGateway/auth`
5. **Token URL**: `https://<CONNECT-ID>.jpmagic.de/hook/GoogleHomeGateway/token`
6. OAuth Client ID + Client Secret generieren → notieren
7. **Home Graph API** aktivieren → API Key erstellen → notieren
8. Unter "Test" → deinen Google Account als Tester hinzufügen

> Die exakte Connect-ID findest du im Gateway-Modul unter der Variable **Fulfillment URL**.

### 2. Gateway Instanz anlegen

1. In Symcon: Neue Instanz → **Google Home Gateway**
2. Eintragen:
   - Google Client ID
   - Google Client Secret
   - Home Graph API Key
   - PIN (mind. 4 Zeichen, selbst wählen)
3. Speichern

### 3. Geräte konfigurieren

Im Konfigurationsformular:
- **Suche nach Geräten** klicken (Auto-Discovery)
- Oder manuell **Hinzufügen**: Name, Typ, Variablen-IDs

### 4. Google Home verknüpfen

1. Google Home App → **+** → **Gerät einrichten** → **Funktioniert mit Google**
2. Nach **IP-Symcon** suchen
3. PIN eingeben
4. Geräte erscheinen in Google Home

### 5. Sync erzwingen (nach Geräteänderungen)

```php
GHGW_RequestSync(<InstanceID>);
```

## Report State

Report State wird **automatisch** getriggert, wenn eine konfigurierte
Symcon-Variable ihren Wert ändert – Google Home zeigt den neuen Zustand
sofort an, ohne erst zu fragen.

## Öffentliche Funktionen

```php
GHGW_RequestSync(int $id): bool       // SYNC neu anfordern
GHGW_ReportAllStates(int $id): void   // Alle Gerätezustände pushen
GHGW_GetFulfillmentURL(int $id): string // Aktuelle Hook-URL ausgeben
```
