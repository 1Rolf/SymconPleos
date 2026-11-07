# Pleos Configurator

[English version](README.en.md)

Der Pleos Configurator der [Pleos-Bibliothek](../README.md) listet die Fahrzeuge, die der API-Schlüssel seines Pleos Account umfasst, und legt auf Wunsch für jedes eine Pleos-Vehicle-Instanz an.

> Dieses Projekt steht in keiner Verbindung zu Hyundai Motor Group, Hyundai, Kia, Genesis oder Pleos.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [PHP-Befehlsreferenz](#5-php-befehlsreferenz)

### 1. Funktionsumfang

- Listet jedes Fahrzeug des API-Schlüssels mit Name, Modell und FIN.
- Legt mit einem Klick eine Pleos-Vehicle-Instanz für ein Fahrzeug an. Die Instanz erhält den Spitznamen des Fahrzeugs oder, ohne Spitznamen, sein Modell als Namen, und die FIN ist bereits eingetragen.
- Zeigt, welche Fahrzeuge bereits eine Instanz haben, sodass keine Duplikate entstehen.
- Listet eine bestehende Pleos-Vehicle-Instanz weiter unter ihrem Namen, auch wenn ihr Fahrzeug nicht mehr zum API-Schlüssel gehört. Die Zeile ist dann rot, damit sich die verwaiste Instanz finden und löschen lässt.
- Listet nur Pleos-Vehicle-Instanzen, die mit demselben Pleos Account verbunden sind, sodass sich mehrere Konten nicht vermischen.
- Umfasst der API-Schlüssel kein Fahrzeug, nennt er die häufigen Ursachen: Der Schlüssel wurde von einem Zweitnutzer statt vom Fahrzeughalter erstellt, er wurde ausgestellt, bevor das Fahrzeug zum Konto hinzugefügt wurde, oder die vernetzten Dienste des Fahrzeugs sind ausgeschaltet.
- Lässt sich die Fahrzeugliste nicht laden, etwa ohne aktiven Pleos Account, weist er darauf hin.

### 2. Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Ein aktiver [Pleos Account](../PleosAccount/README.md)

### 3. Installation

Installieren Sie die Bibliothek „Pleos“ über den Module Store oder die Modulverwaltung und legen Sie die Instanz unter „Konfigurator Instanzen“ → „Pleos Configurator“ an. IP-Symcon verbindet sie mit einem Pleos Account und legt diesen an, falls noch keiner existiert. Die Instanz lässt sich ohne Konfiguration anlegen.

### 4. Konfiguration

Der Pleos Configurator hat keine Eigenschaften. Sein Formular zeigt die Fahrzeugliste „Fahrzeuge“:

| Spalte | Beschreibung |
|---|---|
| Name | Bei einer bestehenden Instanz ihr Name, sonst der in der Hersteller-App vergebene Spitzname oder, ohne Spitznamen, das Modell; eine neu erstellte Instanz erhält diesen Namen |
| Modell | Der Modellname, zum Beispiel „EV2“, oder der Modellcode („QV1“), wenn die API keinen Modellnamen sendet |
| FIN | Die Fahrzeug-Identifizierungsnummer |

Wählen Sie ein Fahrzeug und klicken Sie auf „Erstellen“, um seine Pleos-Vehicle-Instanz anzulegen. Die Fahrzeugliste wird bei jedem Öffnen des Formulars von der API abgefragt.

Der Pleos Configurator setzt keinen eigenen Status.

### 5. PHP-Befehlsreferenz

Der Pleos Configurator exportiert keine Funktionen.
