# Pleos für IP-Symcon

[English version](README.en.md)

Die Bibliothek „Pleos“ bringt die Fahrzeugdaten eines Hyundai, Kia oder Genesis über die Pleos Vehicle Data API nach IP-Symcon.
Sie liest nur: Sie zeigt den Zustand, den das Fahrzeug zuletzt gemeldet hat, etwa Ladezustand, Reichweite, Laden, Kilometerstand, Türen und Verriegelung, Warnungen, Klimatisierung und Standort.
Befehle an das Fahrzeug kann sie nicht senden.

> Dieses Projekt steht in keiner Verbindung zu Hyundai Motor Group, Hyundai, Kia, Genesis oder Pleos und wird von diesen weder unterstützt noch empfohlen.
> Alle Produktnamen sind Marken ihrer jeweiligen Inhaber.

> **Testversion:** Die Bibliothek ist noch nicht im Module Store.
> Bitte melden Sie Probleme und Wünsche als Issue, siehe [Rückmeldungen](#8-rückmeldungen).

### Inhaltsverzeichnis

1. [Module](#1-module)
2. [Funktionsumfang](#2-funktionsumfang)
3. [Voraussetzungen](#3-voraussetzungen)
4. [Kompatibilität](#4-kompatibilität)
5. [API-Schlüssel beantragen](#5-api-schlüssel-beantragen)
6. [Installation und Einrichtung](#6-installation-und-einrichtung)
7. [Wichtige Hinweise](#7-wichtige-hinweise)
8. [Rückmeldungen](#8-rückmeldungen)
9. [Lizenz](#9-lizenz)

### 1. Module

| Modul | Typ | Zweck |
|---|---|---|
| [Pleos Account](PleosAccount/README.md) | Splitter | Hält Marke und API-Schlüssel, holt das Access Token und sendet alle API-Anfragen nacheinander |
| [Pleos Configurator](PleosConfigurator/README.md) | Konfigurator | Listet die Fahrzeuge des API-Schlüssels und legt für jedes eine Pleos-Vehicle-Instanz an |
| [Pleos Vehicle](PleosVehicle/README.md) | Gerät | Fragt die Daten eines Fahrzeugs ab und schreibt die ausgewählten Datenpunkte in Variablen; zeigt eine Kachel |

### 2. Funktionsumfang

- Ein Pleos Account pro API-Schlüssel; beliebig viele Fahrzeuge dieses Schlüssels teilen ihn sich.
- Fahrzeuge werden mit dem Pleos Configurator gefunden und angelegt; die FIN muss nicht eingetippt werden.
- Rund 130 Datenpunkte in zehn Datengruppen: Batterie, Antrieb, Fahrbetrieb, Status, Standort, Kühlbox und vier Fahrt-Datengruppen.
- Sie wählen, welche Datenpunkte zu Variablen werden.
  Variablen entstehen nur, wenn Sie Ihre Auswahl übernehmen, nie zur Laufzeit, und das Modul löscht sie nie.
- Eine Verfügbarkeitsprüfung zeigt, welche Datenpunkte Ihr Fahrzeug tatsächlich liefert, und schlägt sie zur Auswahl vor.
  Eine Meldezeit, ein Fahrtbeginn oder eine Fahrtdauer, die der einzige gültige Wert ihrer Datengruppe ist, wird nicht vorgeschlagen.
- Einheiten je Größe: km oder mi, km/h oder mph, °C oder °F, bar, kPa oder psi.
- Eine Kachel für die Kachel-Visualisierung mit Ladezustand, Reichweite, Kilometerstand, Zeit der letzten Meldung sowie Stecker- und Ladestatus.
- Ein optionaler Karten-Datenpunkt mit OpenStreetMap.
- Client-Secret und Access Token erscheinen nie in der Debug-Ausgabe oder im Meldungsprotokoll, die FIN wird dort gekürzt.

### 3. Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Ein Hyundai, Kia oder Genesis mit vernetzten Diensten, zugelassen in Europa
- Ein API-Schlüssel (Client-ID und Client-Secret) für die Marke des Fahrzeugs aus dem Pleos Playground, siehe [API-Schlüssel beantragen](#5-api-schlüssel-beantragen)

### 4. Kompatibilität

- IP-Symcon 9.0 oder neuer.
- Die Pleos Vehicle Data API steht Privatnutzern nur in Europa zur Verfügung.
- Welche Datenpunkte ein Fahrzeug liefert, hängt von Modell und Software ab.
  Pleos veröffentlicht eine Kompatibilitätsliste; der Kia EV2 funktioniert, obwohl er dort fehlt.
  Die Verfügbarkeitsprüfung des Pleos Vehicle zeigt, was Ihr Fahrzeug liefert.

### 5. API-Schlüssel beantragen

1. Öffnen Sie den Pleos Playground unter https://developers.pleos.ai und melden Sie sich an.
2. Öffnen Sie Workspace → „My Vehicle Data API“.
   Das Menü erscheint nur für Nutzer in Europa.
3. Wählen Sie den Reiter Ihrer Marke, akzeptieren Sie Nutzungsbedingungen und Datenschutzerklärung und melden Sie sich mit dem Herstellerkonto (zum Beispiel Kia Connect) des **Hauptnutzers** des Fahrzeugs an.
   Ein Schlüssel, den ein Zweitnutzer erstellt, umfasst kein Fahrzeug.
4. Die Seite zeigt Client-ID und Client-Secret und sendet eine Bestätigungsmail.
   Tragen Sie beides im Pleos Account ein.

Es gibt einen API-Schlüssel pro Marke.
Er umfasst nur die Fahrzeuge, die beim Ausstellen im Konto waren: Nach dem Hinzufügen eines Fahrzeugs widerrufen Sie den Schlüssel und stellen einen neuen aus.
Der Schlüssel kann ablaufen oder widerrufen werden; der Pleos Account zeigt dann einen Fehlerstatus und Sie benötigen einen neuen Schlüssel.

### 6. Installation und Einrichtung

1. Fügen Sie die Bibliothek in der Modulverwaltung mit der URL `https://github.com/1Rolf/SymconPleos` hinzu.
   Im Module Store ist sie noch nicht.
2. Legen Sie eine **Pleos-Configurator**-Instanz an.
   IP-Symcon verbindet sie mit einer **Pleos-Account**-Instanz und legt diese an, falls noch keine existiert.
3. Öffnen Sie den Pleos Account, wählen Sie die Marke, tragen Sie Client-ID und Client-Secret ein, übernehmen Sie und klicken Sie auf „Verbindung testen“.
4. Öffnen Sie den Pleos Configurator.
   Er listet die Fahrzeuge Ihres API-Schlüssels; legen Sie für jedes gewünschte Fahrzeug eine Pleos-Vehicle-Instanz an.
   Sie können auch direkt mit einem Pleos Vehicle beginnen; es bleibt dann inaktiv, bis eine FIN eingetragen ist.
   Der Pleos Configurator belegt sie vor.
5. Öffnen Sie das Pleos Vehicle, klicken Sie auf „Verfügbarkeitsprüfung starten“, prüfen Sie die vorgeschlagene Auswahl und übernehmen Sie sie.

Details stehen in der README des jeweiligen Moduls.

### 7. Wichtige Hinweise

**Den API-Schlüssel nur an einer Stelle verwenden.** Die API akzeptiert pro API-Schlüssel nur ein Access Token gleichzeitig; ein neues macht das vorherige ohne Rückmeldung ungültig.
Nutzen evcc, Home Assistant, ein zweiter Pleos Account oder ein anderer Client denselben API-Schlüssel gleichzeitig, entziehen sich die Clients gegenseitig das Access Token und Anfragen scheitern mit „Zugriff verweigert“.
Verwenden Sie jeden API-Schlüssel nur in einem einzigen Pleos Account und verbinden Sie alle Fahrzeuge dieses Schlüssels damit.
Um den API-Schlüssel zwischen zwei Symcon-Systemen zu wechseln, im Pleos Account des einen Systems „Aktiv“ abwählen und im anderen auswählen, siehe [Pleos Account](PleosAccount/README.md#1-funktionsumfang).

**Nur die letzte Fahrzeugmeldung.** Die API liefert den Zustand, den das Fahrzeug zuletzt an das Backend des Herstellers gesendet hat.
Sie kontaktiert oder weckt das Fahrzeug nie, und ein Senden lässt sich nicht erzwingen.
Fahrzeuge senden bei bestimmten Ereignissen, etwa beim Entriegeln oder Fahren; Werte können daher hinter der Wirklichkeit zurückliegen, und das Einstecken oder der Ladebeginn erscheinen oft verspätet.
Jede Datengruppe außer den Fahrt-Datengruppen hat einen Meldezeit-Datenpunkt, der zeigt, wie alt ihre Werte sind; in den Fahrt-Datengruppen übernimmt der Fahrtbeginn diese Rolle.
In den ersten 24 Stunden nach Ausstellen des API-Schlüssels hat die API eventuell noch keine Daten.

**Archivierung.** Das Modul aktiviert nie die Archivierung seiner Variablen.
Ob und welche Variablen Sie archivieren, entscheiden Sie.

**Datenschutz.**
- Das Client-Secret wird in einem Passwortfeld eingegeben, IP-Symcon speichert Eigenschaften aber im Klartext in seinen Einstellungen und deren Sicherungen.
- Der Karten-Datenpunkt ist standardmäßig aus.
  Wird die Karte angezeigt, geht die Position des Fahrzeugs an OpenStreetMap.
- „Diagnosedaten exportieren“ im Pleos Vehicle ersetzt FIN, Spitzname und Position durch Platzhalter, sodass Sie das Ergebnis für Support-Anfragen weitergeben können.

### 8. Rückmeldungen

Die Bibliothek ist eine Testversion.
Bitte melden Sie Probleme und Wünsche als Issue unter https://github.com/1Rolf/SymconPleos/issues.
Hängen Sie bei Support-Anfragen das Ergebnis von „Diagnosedaten exportieren“ aus dem Pleos Vehicle an; es ersetzt FIN, Spitzname und Position durch Platzhalter.

Dieses Repository erhält die Releases der Bibliothek; entwickelt wird sie an anderer Stelle.
Ein Pull Request wird deshalb nicht hier gemergt, sondern in das nächste Release übernommen, mit Ihnen als Co-Autor.

### 9. Lizenz

[CC BY-NC-SA 4.0](LICENSE)
