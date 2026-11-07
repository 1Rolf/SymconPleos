# Pleos Account

[English version](README.en.md)

Der Pleos Account ist der Splitter der [Pleos-Bibliothek](../README.md). Er hält Marke und API-Schlüssel eines Pleos-Playground-Kontos, besitzt das einzige Access Token dieses Schlüssels und sendet jede Anfrage seiner Pleos-Configurator- und Pleos-Vehicle-Instanzen nacheinander an die Pleos Vehicle Data API.

> Dieses Projekt steht in keiner Verbindung zu Hyundai Motor Group, Hyundai, Kia, Genesis oder Pleos.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Status](#5-status)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

- Holt mit dem API-Schlüssel das Access Token und verwendet es wieder. Lehnt die API es ab (HTTP 401 oder 403), holt der Pleos Account einmal ein neues und wiederholt die Anfrage einmal.
- Sendet alle Anfragen nacheinander, sodass sich mehrere Fahrzeuge eines API-Schlüssels nie gegenseitig das Access Token entziehen.
- Beachtet eine Anfragebegrenzung (HTTP 429): Alle Anfragen pausieren bis zur Zeit aus `Retry-After`, mindestens 5 Minuten; danach kehrt der Status von selbst zu aktiv zurück.
- Serverfehler und Zeitüberschreitungen (30 s) setzen keinen Fehlerstatus; die Anfrage wird bei der nächsten Abfrage wiederholt. Das Meldungsprotokoll erhält eine Warnung pro Ausfall, nicht eine pro fehlgeschlagener Anfrage.
- Lehnt die API die Token-Anfrage ab, ohne die Zugangsdaten zu beanstanden (z. B. HTTP 403), bleibt der Status aktiv. Das Meldungsprotokoll erhält eine Warnung, bis wieder ein Access Token kommt. Nur HTTP 400, HTTP 404 mit Code 40404 und eine Antwort ohne Access Token gelten als ungültiger API-Schlüssel (Status 201); das Meldungsprotokoll erhält dann einen Fehler, wenn der Status wechselt.
- Nach einer fehlgeschlagenen Token-Anfrage folgt die nächste frühestens nach 60 Sekunden. Anfragen in dieser Zeit werden nicht gesendet, z. B. beim Export der Diagnosedaten. „Verbindung testen“ und geänderte Einstellungen fragen sofort.
- Client-Secret, Access Token und Authorization-Header erscheinen nie in der Debug-Ausgabe oder im Meldungsprotokoll; FINs werden auf ihre ersten 3 und letzten 4 Zeichen gekürzt.

**Den API-Schlüssel nur in diesem Pleos Account verwenden.** Die API akzeptiert pro API-Schlüssel nur ein Access Token gleichzeitig. Nutzen evcc, Home Assistant, ein anderer Pleos Account oder ein anderer Client denselben API-Schlüssel gleichzeitig, entziehen sich die Clients gegenseitig das Access Token, und dieser Pleos Account landet im Status „Zugriff verweigert“.

### 2. Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Ein API-Schlüssel aus dem Pleos Playground, siehe [API-Schlüssel beantragen](../README.md#5-api-schlüssel-beantragen)

### 3. Installation

Installieren Sie die Bibliothek „Pleos“ über den Module Store oder die Modulverwaltung. Ein Pleos Account entsteht meist zusammen mit dem ersten Pleos Configurator; er lässt sich auch einzeln unter „Splitter Instanzen“ → „Pleos Account“ anlegen. Eine Instanz lässt sich ohne Konfiguration anlegen; sie bleibt inaktiv, bis Marke, Client-ID und Client-Secret eingetragen sind.

### 4. Konfiguration

| Eigenschaft | Beschreibung |
|---|---|
| Marke | Hyundai, Kia oder Genesis: die Marke, für die der API-Schlüssel ausgestellt wurde. Muss ausgewählt werden. |
| Client-ID | Die Client-ID des API-Schlüssels |
| Client-Secret | Das Client-Secret des API-Schlüssels, eingegeben in einem Passwortfeld |

| Aktion | Beschreibung |
|---|---|
| Verbindung testen | Holt ein neues Access Token und meldet, ob API-Schlüssel und Marke funktionieren |

### 5. Status

| Code | Status | Bedeutung |
|---|---|---|
| 102 | Aktiv | Die API akzeptiert den API-Schlüssel |
| 104 | Inaktiv | Marke, Client-ID oder Client-Secret fehlt |
| 201 | Client-ID, Client-Secret oder Marke ungültig oder API-Schlüssel abgelaufen | Die Token-Anfrage ist fehlgeschlagen. Prüfen Sie die Eingaben; stimmen sie, stellen Sie im Pleos Playground einen neuen API-Schlüssel aus. |
| 202 | Zugriff verweigert: Der API-Schlüssel wird möglicherweise von einem anderen Client verwendet oder wurde widerrufen | Die API hat ein frisch geholtes Access Token abgelehnt. Stellen Sie sicher, dass kein anderer Client den API-Schlüssel nutzt, oder stellen Sie einen neuen aus. |
| 203 | Anfragen begrenzt: Die Anfragen pausieren für einige Minuten | Die API begrenzt die Anzahl der Anfragen. Der Status endet nach der Pause von selbst. |

### 6. PHP-Befehlsreferenz

```php
string PLEOS_TestConnection(int $InstanceID);
```
Holt sofort ein neues Access Token, auch während der 60-Sekunden-Pause nach einer fehlgeschlagenen Token-Anfrage, und gibt eine Meldung zurück, ob die Verbindung funktioniert. Setzt den Status auf aktiv oder, bei einem ungültigen API-Schlüssel, auf 201.

Beispiel:
```php
echo PLEOS_TestConnection(12345);
```

Der Pleos Account exportiert keine weiteren Funktionen. Pleos Configurator und Pleos Vehicle senden ihre Anfragen über den Datenfluss.
