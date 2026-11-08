# Pleos Vehicle

[English version](README.en.md)

Das Pleos Vehicle der [Pleos-Bibliothek](../README.md) steht für ein Fahrzeug. Es fragt die ausgewählten Datengruppen über seinen Pleos Account in einem festen Intervall ab und schreibt die ausgewählten Datenpunkte, umgerechnet in Ihre Einheiten, in Variablen. Außerdem zeigt es eine Kachel in der Kachel-Visualisierung.

> Dieses Projekt steht in keiner Verbindung zu Hyundai Motor Group, Hyundai, Kia, Genesis oder Pleos.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Datengruppen und Datenpunkte](#5-datengruppen-und-datenpunkte)
6. [Verfügbarkeitsprüfung](#6-verfügbarkeitsprüfung)
7. [Visualisierung](#7-visualisierung)
8. [Status und Meldungen](#8-status-und-meldungen)
9. [PHP-Befehlsreferenz](#9-php-befehlsreferenz)

### 1. Funktionsumfang

- Fragt die Datengruppen mit mindestens einem ausgewählten Datenpunkt in einem festen Intervall (standardmäßig 15 Minuten) und auf Anforderung ab.
- Eine fehlschlagende Datengruppe hält die anderen nicht von der Aktualisierung ab.
- Variablen gibt es nur für die Datenpunkte, die Sie ausgewählt und übernommen haben. Das Modul legt beim Abfragen nie Variablen an und löscht nie welche.
- Die Verfügbarkeitsprüfung zeigt, welche Datenpunkte Ihr Fahrzeug liefert, und schlägt sie zur Auswahl vor.
- Werte werden in Ihre Einheiten umgerechnet; `null` und `"invalid"` überschreiben nie den letzten gültigen Wert.
- Fahrt-Datengruppen werden höchstens stündlich und nur für neue Fahrten abgefragt.
- Diagnosedaten-Export, bei dem FIN, Spitzname und Position durch Platzhalter ersetzt sind.

**Nur die letzte Fahrzeugmeldung.** Die API liefert den Zustand, den das Fahrzeug zuletzt an das Backend des Herstellers gesendet hat. Sie kontaktiert oder weckt das Fahrzeug nie. Werte können daher hinter der Wirklichkeit zurückliegen; der Meldezeit-Datenpunkt jeder Datengruppe zeigt, wie alt ihre Werte sind. Die Fahrt-Datengruppen haben keine Meldezeit: Der Fahrtbeginn übernimmt diese Rolle.

### 2. Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Ein aktiver [Pleos Account](../PleosAccount/README.md), dessen API-Schlüssel das Fahrzeug umfasst

### 3. Installation

Legen Sie die Instanz mit dem [Pleos Configurator](../PleosConfigurator/README.md) an, der FIN und Namen vorbelegt. Sie lässt sich auch von Hand unter „Instanzen“ → „Pleos Vehicle“ anlegen; dann tragen Sie die FIN selbst ein. Die Instanz lässt sich ohne Konfiguration anlegen und bleibt inaktiv, bis eine FIN eingetragen ist.

### 4. Konfiguration

| Eigenschaft | Standard | Beschreibung |
|---|---|---|
| FIN | | Die Fahrzeug-Identifizierungsnummer |
| Abfrageintervall | 15 min | Wie oft die Datengruppen abgefragt werden, in Minuten (mindestens 1) |

**Einheiten** (aufklappbarer Bereich):

| Eigenschaft | Auswahl | Standard |
|---|---|---|
| Entfernung | Kilometer (km), Meilen (mi) | km |
| Geschwindigkeit | Kilometer pro Stunde (km/h), Meilen pro Stunde (mph) | km/h |
| Temperatur | Celsius (°C), Fahrenheit (°F) | °C |
| Reifendruck | Bar (bar), Kilopascal (kPa), Pfund pro Quadratzoll (psi) | bar |
| Kilometerstand wird als Meilen gemeldet, ist aber in km | an/aus | aus |

Werte werden aus der Einheit, die das Fahrzeug meldet, in Ihre Auswahl umgerechnet. Eine geänderte Einheit rechnet bereits archivierte Werte nicht um. Manche Fahrzeuge melden einen Kilometerstand in km als Meilen; die Kilometerstand-Option behandelt die gemeldeten Meilen dann als km.

**Auswahl der Datenpunkte:** Ein aufklappbarer Bereich pro Datengruppe listet ihre Datenpunkte mit dem Kästchen „Ausgewählt“, dem letzten Wert aus der Verfügbarkeitsprüfung und einem Hinweis. Übernehmen legt Variablen für die angehakten Datenpunkte an. Abgewählte Datenpunkte behalten ihre Variablen: Diese werden nicht mehr aktualisiert und nicht automatisch gelöscht, und das Formular kennzeichnet sie mit „Variable bleibt erhalten, wird nicht mehr aktualisiert.“ Löschen Sie sie manuell, wenn Sie sie nicht mehr benötigen. Wird ein Datenpunkt wieder angehakt, schreibt das Modul weiter in seine bestehende Variable.

| Aktion | Beschreibung |
|---|---|
| Jetzt aktualisieren | Fragt die ausgewählten Datengruppen sofort ab, siehe `PLEOS_Update` |
| Verfügbarkeitsprüfung starten | Siehe [Verfügbarkeitsprüfung](#6-verfügbarkeitsprüfung) |
| Diagnosedaten exportieren | Lädt `pleos-diagnostics.json` mit den Rohantworten herunter, siehe `PLEOS_ExportDiagnostics` |

### 5. Datengruppen und Datenpunkte

Ein neues Pleos Vehicle hat alle Datenpunkte der fünf Kern-Datengruppen ausgewählt (Batterie, Antrieb, Fahrbetrieb, Status, Standort), einschließlich ihrer Meldezeiten, außer der Karte. Die übrigen Datengruppen sind anfangs abgewählt.

| Datengruppe | Datenpunkte |
|---|---|
| Batterie | Meldezeit Batterie, Ladezustand, Ladekabel (verbunden/getrennt), Ladestatus (acht Werte), Lädt, Batteriekapazität, Ladelimit AC und DC, Restladezeit |
| Antrieb | Meldezeit Antrieb, Tankfüllstand, Ladestand Hochvoltbatterie, Reichweite je Reichweitentyp: EV, Verbrenner, HEV, Gesamt- und elektrische Reichweite (PHEV), FCEV |
| Fahrbetrieb | Meldezeit Fahrbetrieb, Zündung, Zündung (IGN1), Hochvoltsystem (IGN3), Zubehörstrom (ACC), Schlafmodus, Kilometerstand, In Parkstellung |
| Status | Meldezeit Status, jede Tür (offen, geschlossen, verriegelt), Fahrzeug verriegelt, Motorhaube, Kofferraum, Schiebedach, Reifendruck je Rad, Klimamodus, Klimatisierung, Gebläsestufe, Klima-Solltemperatur, Frontscheibenenteisung, Lenkradheizung, Sitzheizung und Sitzbelüftung je Sitz, Außentemperatur, Anzahl Warnungen, eine Warnung je Warncode (17) |
| Standort | Meldezeit Standort, Breitengrad, Längengrad, Höhe, Fahrtrichtung, Geschwindigkeit, Koordinatensystem, Karte (standardmäßig aus) |
| Kühlbox | Meldezeit Kühlbox, Kühlbox, Kühlboxtemperatur |
| Fahrt Antrieb | Fahrtbeginn und Fahrtdauer, minimale und maximale Spannung der Hochvoltbatterie, maximale Motoröltemperatur, maximale Motordrehzahl, Rohdaten der letzten Fahrt |
| Fahrt Geschwindigkeit | Fahrtbeginn und Fahrtdauer, Höchst- und Durchschnittsgeschwindigkeit, maximale Raddrehzahl je Rad, Rohdaten der letzten Fahrt |
| Fahrt Fahrbetrieb | Fahrtbeginn und Fahrtdauer, Fahrtstrecke, Kilometerstand am Fahrtende, maximale Beschleunigung und Verzögerung, maximale Gierrate, maximaler Lenkwinkel und maximale Lenkgeschwindigkeit, Zündung, Gang, Gurt Fahrer und Beifahrer, Geschwindigkeitsassistent (zwei Optionen) am Fahrtende, Rohdaten der letzten Fahrt |
| Fahrt Status | Fahrtbeginn und Fahrtdauer, jede Tür am Fahrtende, minimaler Reifendruck je Rad, durchschnittliche Außentemperatur, Lichtschalter am Fahrtende, Tankwarnung am Fahrtende, Rohdaten der letzten Fahrt |

**Abgeleitete Datenpunkte** berechnet das Modul aus anderen Werten derselben Datengruppe:
- Fahrzeug verriegelt: wahr, wenn alle Türen verriegelt sind.
- Anzahl Warnungen und ein Wahrheitswert je Warncode.
- Reichweite je Reichweitentyp. Plug-in-Hybride melden zwei PHEV-Einträge; der größere ist die Gesamtreichweite, der kleinere die elektrische Reichweite.
- Ladekabel: verbunden oder getrennt.

**Regeln für Werte:**
- `null`, `"invalid"` und fehlende Werte behalten den letzten gültigen Wert der Variable.
- Reifendruck-Rohwerte werden nach ihrer gemeldeten Einheit dekodiert; 255 bedeutet kein Wert.
- Die Reichweite wird aus ihrem Text gelesen; `"-"` und `"invalid"` bedeuten kein Wert.
- Die Batteriekapazität wird in kWh umgerechnet.

**Karte:** eine String-Variable mit Web-Content-Darstellung, die OpenStreetMap an der Position des Fahrzeugs einbettet. Sie ist standardmäßig aus: Die Position wird bei jeder Anzeige der Karte an OpenStreetMap übertragen. Eine Karte erscheint nur für WGS84-Koordinaten.

**Fahrten:** Fahrt-Datengruppen werden höchstens einmal pro Stunde für bis zu 7 zurückliegende Tage abgefragt (2 Tage, wenn die API den längeren Zeitraum ablehnt), und nur Fahrten, die nach der zuletzt verarbeiteten Fahrt begonnen haben, werden verwendet. Eine fehlgeschlagene Fahrt-Abfrage (keine Antwort, Serverfehler, Anfragelimit) wird bei der nächsten Abfrage wiederholt. Ihre Datenpunkte beschreiben die letzte neue Fahrt. Die Rohdaten der letzten Fahrt sind das JSON der Fahrt für eigene Skripte, gekürzt, wenn es 64 KiB überschreitet, und standardmäßig aus.

### 6. Verfügbarkeitsprüfung

„Verfügbarkeitsprüfung starten“ fragt jede Datengruppe einmal ab, auch die abgewählten. Sie zeigt je Datengruppe, ob sie Daten geliefert hat („Geliefert“), noch keine Daten hatte („Noch keine Daten“, in den ersten 24 Stunden normal) oder fehlgeschlagen ist, zusammen mit ihrer Meldezeit; bei Fahrt-Datengruppen steht dafür der Beginn der letzten Fahrt. Der letzte Wert jedes Datenpunkts erscheint in den Auswahllisten.

Danach hakt sie in den Auswahllisten einen Vorschlag an: die Datenpunkte mit gültigem Wert. Der Vorschlag wird nicht übernommen: Prüfen Sie ihn und übernehmen Sie, um die Variablen anzulegen. Wiederholen Sie die Prüfung jederzeit, etwa nach einem Software-Update des Fahrzeugs oder nach den ersten 24 Stunden.

Der Vorschlag weicht in drei Punkten von „jeder Datenpunkt mit Wert“ ab:
- Standardmäßig ausgeschaltete Datenpunkte, die Karte und die Rohdaten der letzten Fahrt, werden nie vorgeschlagen; sie behalten ihren aktuellen Haken.
- Datenpunkte, die nicht zum Fahrzeugtyp aus der Fahrzeugliste passen, etwa der Tankfüllstand oder die Verbrenner-Reichweite eines Elektrofahrzeugs, werden nicht vorgeschlagen, bleiben aber auswählbar.
- Eine Meldezeit, ein Fahrtbeginn oder eine Fahrtdauer wird nicht vorgeschlagen, wenn sie der einzige gültige Wert ihrer Datengruppe ist: Ein Zeitstempel allein sagt nichts aus (der Kia EV2 meldet etwa eine Meldezeit Kühlbox ohne Kühlbox-Werte).

### 7. Visualisierung

Die Kachel zeigt Ladezustand, Reichweite, Kilometerstand, die Meldezeit der Batterie („vor 12 Min.“) und ein Symbol für Ladekabel und Laden. Sie liest die Variablen der Instanz, zeigt „—“ für Datenpunkte, die nicht ausgewählt sind oder noch keinen Wert haben, und folgt dem hellen und dunklen Design. Die Schrift wächst mit der Kachelgröße; die Schriftgröße der Visualisierung ist die Untergrenze. Ihre Texte erscheinen in der Sprache des Benutzers, der sie ansieht. Als Reichweite dient der erste Reichweiten-Datenpunkt mit Wert, in der Reihenfolge EV, elektrische Reichweite (PHEV), Gesamtreichweite (PHEV), FCEV, HEV, Verbrenner.

### 8. Status und Meldungen

| Code | Status | Bedeutung |
|---|---|---|
| 102 | Aktiv | Das Fahrzeug wird abgefragt |
| 104 | Inaktiv | Keine FIN eingetragen, oder der Pleos Account ist inaktiv (ausgeschaltet oder nicht konfiguriert). Ein inaktiver Pleos Account ist kein Fehler; das Fahrzeug folgt ihm, sobald er wieder aktiv ist. |
| 201 | Kein aktiver Pleos Account | Die Instanz ist mit keinem Pleos Account verbunden, oder der Pleos Account meldet einen Fehler (API-Schlüssel ungültig, Zugriff verweigert). Das Fahrzeug zeigt das, sobald der Pleos Account seinen Status ändert, und fragt weiter ab, damit sich der Pleos Account erholen kann. |
| 202 | Fahrzeug nicht vom API-Schlüssel abgedeckt | Die API meldet das Fahrzeug als nicht registriert (4101) oder ohne Einwilligung (5006). Stellen Sie einen neuen API-Schlüssel aus, der das Fahrzeug umfasst. |
| 203 | Pleos Account durch Anfragelimit pausiert | Die Pleos-API begrenzt die Anzahl der Anfragen, und der Pleos Account pausiert sie für einige Minuten. Danach wird das Fahrzeug wieder abgefragt. |

„Keine Daten“ (4045) für eine Datengruppe behält ihre Werte und setzt keinen Fehlerstatus. Serverfehler und Zeitüberschreitungen lassen den Status unverändert; der Pleos Account warnt davor. Kann der Pleos Account eine Anfrage nicht senden (kein Access Token, Anfragelimit) oder antwortet die API nicht, fragt die Abfrage keine weiteren Datengruppen ab; die nächste Abfrage versucht es erneut.

Das Meldungsprotokoll erhält jeweils eine Meldung, die über Neustarts hinweg gemerkt wird:
- „Neue Daten verfügbar für …“, wenn ein abgewählter Datenpunkt Werte zu liefern beginnt. Wiederholen Sie die Verfügbarkeitsprüfung, um ihn auszuwählen.
- „Die API liefert das unbekannte Feld …“, wenn eine Datengruppe ein Feld liefert, das das Modul nicht kennt. Bitte melden Sie es, damit ein Modul-Update es unterstützen kann.
- „Die API liefert den Wert …, für den es keine Beschriftung gibt“, wenn ein Datenpunkt mit Beschriftungen (z. B. Ladestatus) einen Wert erhält, den das Modul nicht kennt. Die Variable zeigt den Rohwert. Bitte melden Sie ihn, damit ein Modul-Update ihn unterstützen kann.

### 9. PHP-Befehlsreferenz

```php
void PLEOS_Update(int $InstanceID);
```
Fragt die letzte Fahrzeugmeldung der ausgewählten Datengruppen ab und schreibt die ausgewählten Datenpunkte in ihre Variablen. Der Abfrage-Timer ruft dieselbe Funktion auf.

```php
void PLEOS_RunAvailabilityCheck(int $InstanceID);
```
Führt die [Verfügbarkeitsprüfung](#6-verfügbarkeitsprüfung) aus. Ergebnis und Vorschlag erscheinen im geöffneten Konfigurationsformular; sie ändert keine Eigenschaft und keine Variable.

```php
string PLEOS_ExportDiagnostics(int $InstanceID);
```
Gibt die Rohantworten der Fahrzeugliste und aller Datengruppen als JSON zurück, wobei FIN, Spitzname, Breitengrad, Längengrad und Höhe durch Platzhalter ersetzt sind. Geben Sie es bei Support-Anfragen weiter.

```php
string PLEOS_GetVisualizationTile(int $InstanceID);
```
Gibt das HTML der Kachel zurück. Die Kachel-Visualisierung ruft sie auf; Skripte benötigen sie nicht.

Beispiel:
```php
PLEOS_Update(12345);
echo PLEOS_ExportDiagnostics(12345);
```
