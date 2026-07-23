# Smart Gemini IO

SmartGeminiIO ist ein Type 1 I/O Modul für die Google Gemini API, das als zentraler API-Client für alle IP-Symcon KI-Module dient. Es verwaltet den API-Schlüssel und das Modell an einer einzigen Stelle und stellt eine einheitliche Schnittstelle zur Verfügung.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Type 1 I/O Modul für die Google Gemini API.
* Zentrale Verwaltung von Google Gemini API-Key und Modell für alle angeschlossenen KI-Module.
* Bereitstellung einer Query-Funktion für strukturierte und unstrukturierte Prompts inklusive JSON-Schema-Unterstützung.
* Überwachung und Statistik der API-Anfragen (Erfolgreich, Fehlgeschlagen, Letzter Fehler).

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `Smart Gemini IO` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartTools`

### 4. Konfiguration

* **ApiKey:** Der Google Gemini API-Key für die Authentifizierung.
* **Model:** Das zu verwendende Modell (z.B. gemini-2.5-flash). Empfohlen: gemini-2.5-flash oder gemini-2.5-pro.
* **TimeoutSeconds:** Timeout für API-Anfragen in Sekunden (Standard: 30, max: 120).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| TotalRequests | Gesamt-Anfragen | Integer | Anzahl aller bisher durchgeführten API-Anfragen |
| SuccessfulRequests | Erfolgreiche Anfragen | Integer | Anzahl der erfolgreich abgeschlossenen API-Anfragen |
| FailedRequests | Fehlgeschlagene Anfragen | Integer | Anzahl der fehlgeschlagenen API-Anfragen |
| LastError | Letzter Fehler | String | Die Fehlermeldung der zuletzt fehlgeschlagenen API-Anfrage |
| LastModel | Letztes Modell | String | Das zuletzt verwendete Modell für eine Anfrage |

### 6. PHP-Befehlsreferenz

```php
GIO_Query(int $InstanceID, string $userPrompt, string $systemInstruction = '', string $schemaJson = '', float $temperature = 0.1);
```
Sendet einen Prompt an die Gemini API und gibt den extrahierten JSON-Text zurück. Kann mit Systemanweisungen und einem JSON-Schema für die Antwort aufgerufen werden.

```php
GIO_FindInstance(int $InstanceID);
```
Gibt die Instance-ID des ersten verfügbaren SmartGeminiIO zurück. Nützlich für Callers zur Auto-Discovery.

```php
GIO_TestConnection(int $InstanceID);
```
Führt einen schnellen Verbindungstest zur Gemini API durch und überprüft die Konfiguration.

```php
GIO_ResetCounters(int $InstanceID);
```
Setzt alle Statistik-Zähler (Gesamt, Erfolgreich, Fehlgeschlagen) sowie den letzten Fehler auf 0 bzw. leer zurück.
