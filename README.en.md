# Pleos for IP-Symcon

[Deutsche Version](README.md)

The library "Pleos" brings the vehicle data of a Hyundai, Kia or Genesis vehicle into IP-Symcon through the Pleos Vehicle Data API.
It is read-only: it shows the state the vehicle last reported, such as state of charge, range, charging, odometer, doors and locks, warnings, climate and position.
It cannot send commands to the vehicle.

> This project is not affiliated with, endorsed by or supported by Hyundai Motor Group, Hyundai, Kia, Genesis or Pleos.
> All product names are trademarks of their respective owners.

> **Test version:** the library is not in the Module Store yet.
> Please report problems and wishes as an issue, see [Feedback](#8-feedback).

### Contents

1. [Modules](#1-modules)
2. [Features](#2-features)
3. [Requirements](#3-requirements)
4. [Compatibility](#4-compatibility)
5. [Getting an API Key](#5-getting-an-api-key)
6. [Installation and setup](#6-installation-and-setup)
7. [Important notes](#7-important-notes)
8. [Feedback](#8-feedback)
9. [License](#9-license)

### 1. Modules

| Module | Type | Purpose |
|---|---|---|
| [Pleos Account](PleosAccount/README.en.md) | Splitter | Holds the Brand and the API Key, fetches the Access Token and sends every API request one after another |
| [Pleos Configurator](PleosConfigurator/README.en.md) | Configurator | Lists the vehicles of the API Key and creates a Pleos Vehicle instance for each of them |
| [Pleos Vehicle](PleosVehicle/README.en.md) | Device | Polls the data of one vehicle and writes the selected Data Points into variables; shows a tile |

### 2. Features

- One Pleos Account per API Key; any number of vehicles of that key share it.
- Vehicles are found and created with the Pleos Configurator; no need to type a VIN.
- About 130 Data Points in ten Data Groups: Battery, Powertrain, Driving, Status, Location, Freezer and four Trip Data Groups.
- You choose which Data Points become variables.
  Variables are created only when you apply your selection, never at runtime, and are never deleted by the module.
- An Availability Check shows which Data Points your vehicle actually delivers and proposes them for selection.
  A Report Time, Trip start or Trip duration that is the only valid value of its Data Group is not proposed.
- Units per quantity: km or mi, km/h or mph, °C or °F, bar, kPa or psi.
- A tile for the tile visualization with state of charge, range, odometer, time of the last report and charging state.
- An optional map Data Point with OpenStreetMap.
- The Client Secret and the Access Token never appear in debug output or the message log, and the VIN is shortened there.

### 3. Requirements

- IP-Symcon 9.0 or later
- A Hyundai, Kia or Genesis vehicle with connected services, registered in Europe
- An API Key (Client ID and Client Secret) for the vehicle's Brand from the Pleos Playground, see [Getting an API Key](#5-getting-an-api-key)

### 4. Compatibility

- IP-Symcon 9.0 or later.
- The Pleos Vehicle Data API serves private users in Europe only.
- Which Data Points a vehicle delivers depends on its model and software.
  Pleos publishes a compatibility list; the Kia EV2 works although it is not listed.
  Run the Availability Check of the Pleos Vehicle to see what your vehicle delivers.

### 5. Getting an API Key

1. Open the Pleos Playground at https://developers.pleos.ai and sign in.
2. Open Workspace → "My Vehicle Data API".
   The menu is shown to users in Europe only.
3. Pick the tab of your Brand, accept the terms and the privacy policy and log in with the manufacturer account (for example Kia Connect) of the vehicle's **primary user**.
   A key created by a secondary user covers no vehicle.
4. The page shows the Client ID and the Client Secret and sends a confirmation mail.
   Enter both in the Pleos Account.

There is one API Key per Brand.
It covers only the vehicles that were in your account when the key was issued: after adding a vehicle, revoke the key and issue a new one.
The key can expire or be revoked; the Pleos Account then shows an error status and you need a new key.

### 6. Installation and setup

1. Add the library in Module Control with the URL `https://github.com/1Rolf/SymconPleos`.
   It is not in the Module Store yet.
2. Create a **Pleos Configurator** instance.
   IP-Symcon connects it to a **Pleos Account** instance, creating one if none exists.
3. Open the Pleos Account, select the Brand, enter the Client ID and the Client Secret, apply and press "Test connection".
4. Open the Pleos Configurator.
   It lists the vehicles of your API Key; create a Pleos Vehicle instance for each vehicle you want.
   You can also start with a Pleos Vehicle; it then stays inactive until a VIN is entered.
   The Pleos Configurator presets it.
5. Open the Pleos Vehicle, press "Run Availability Check", review the proposed selection and apply it.

Details are in the README of each module.

### 7. Important notes

**Use the API Key in one place only.** The API accepts only one Access Token per API Key at a time; fetching a new one silently invalidates the previous one.
If evcc, Home Assistant, a second Pleos Account or any other client uses the same API Key at the same time, the clients keep revoking each other's Access Token and requests fail with "access denied".
Use each API Key in a single Pleos Account only, and connect all vehicles of that key to it.
To move the API Key between two Symcon systems, clear "Active" in the Pleos Account on the one system and set it on the other, see [Pleos Account](PleosAccount/README.en.md#1-features).

**Only the last Vehicle Report.** The API returns the state the vehicle last uploaded to the manufacturer's backend.
It never contacts or wakes the vehicle, and there is no way to force an upload.
Vehicles upload on certain events, such as unlocking or driving, so values can lag behind reality; plugging in or the start of charging often appears late.
Each Data Group except the Trip Data Groups has a Report Time Data Point that tells how old its values are; in the Trip Data Groups, the Trip start serves that role.
During the first 24 hours after the API Key was issued, the API may have no data yet.

**Archiving.** The module never enables archiving for its variables.
Whether and which variables you archive is your decision.

**Privacy.**
- The Client Secret is entered in a password field, but IP-Symcon stores properties in plain text in its settings and their backups.
- The map Data Point is off by default.
  When it is displayed, the vehicle's position is sent to OpenStreetMap.
- "Export diagnostics" in the Pleos Vehicle replaces the VIN, the nickname and the position with placeholders, so you can share the result when asking for support.

### 8. Feedback

The library is a test version.
Please report problems and wishes as an issue at https://github.com/1Rolf/SymconPleos/issues.
When asking for support, attach the result of "Export diagnostics" from the Pleos Vehicle; it replaces the VIN, the nickname and the position with placeholders.

This repository receives the releases of the library; it is developed elsewhere.
A pull request is therefore not merged here but taken over into the next release, with you as co-author.

### 9. License

[CC BY-NC-SA 4.0](LICENSE)
