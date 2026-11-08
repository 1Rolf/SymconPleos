# Pleos Vehicle

[Deutsche Version](README.md)

The Pleos Vehicle of the [Pleos library](../README.en.md) represents one vehicle. It polls the selected Data Groups through its Pleos Account at a fixed interval and writes the selected Data Points into variables, converted into your units. It also shows a tile in the tile visualization.

> This project is not affiliated with Hyundai Motor Group, Hyundai, Kia, Genesis or Pleos.

### Contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [Data Groups and Data Points](#5-data-groups-and-data-points)
6. [Availability Check](#6-availability-check)
7. [Visualization](#7-visualization)
8. [Statuses and messages](#8-statuses-and-messages)
9. [PHP functions](#9-php-functions)

### 1. Features

- Polls the Data Groups with at least one selected Data Point at a fixed interval (default 15 minutes) and on demand.
- One failing Data Group does not keep the others from updating.
- Variables exist only for the Data Points you selected and applied. The module never creates variables while polling and never deletes any.
- The Availability Check shows which Data Points your vehicle delivers and proposes them for selection.
- Values are converted into your units; `null` and `"invalid"` never overwrite the last valid value.
- Trip Data Groups are requested at most hourly and only for new Trips.
- Diagnostics export with VIN, nickname and position replaced by placeholders.

**Only the last Vehicle Report.** The API returns the state the vehicle last uploaded to the manufacturer's backend. It never contacts or wakes the vehicle. Values can therefore lag behind reality; the Report Time Data Point of each Data Group tells how old its values are. The Trip Data Groups have no Report Time: the Trip start serves that role.

### 2. Requirements

- IP-Symcon 9.0 or later
- An active [Pleos Account](../PleosAccount/README.en.md) whose API Key covers the vehicle

### 3. Installation

Create the instance with the [Pleos Configurator](../PleosConfigurator/README.en.md), which presets the VIN and the name. It can also be created by hand under "Instances" → "Pleos Vehicle"; then enter the VIN yourself. The instance can be created without any configuration and stays inactive until a VIN is entered.

### 4. Configuration

| Property | Default | Description |
|---|---|---|
| VIN | | The vehicle identification number |
| Polling interval | 15 min | How often the Data Groups are requested, in minutes (at least 1) |

**Units** (expandable panel):

| Property | Choices | Default |
|---|---|---|
| Distance | Kilometers (km), Miles (mi) | km |
| Speed | Kilometers per hour (km/h), Miles per hour (mph) | km/h |
| Temperature | Celsius (°C), Fahrenheit (°F) | °C |
| Tire pressure | Bar (bar), Kilopascal (kPa), Pounds per square inch (psi) | bar |
| Odometer reported as miles but is km | on/off | off |

Values are converted from the unit the vehicle reports into your choice. Changing a unit does not convert values that are already archived. Some vehicles report a km odometer labelled as miles; the odometer option then treats the reported miles as km.

**Data Point selection:** one expandable panel per Data Group lists its Data Points with a "Selected" tick box, the latest value from the Availability Check and a note. Apply to create variables for the ticked Data Points. Deselected Data Points keep their variables: they are no longer updated and not deleted automatically, and the form marks them with "Variable kept, no longer updated." Delete them manually if you no longer need them. Ticking a Data Point again continues writing into its existing variable.

| Action | Description |
|---|---|
| Update now | Requests the selected Data Groups now, see `PLEOS_Update` |
| Run Availability Check | See [Availability Check](#6-availability-check) |
| Export diagnostics | Downloads `pleos-diagnostics.json` with the raw responses, see `PLEOS_ExportDiagnostics` |

### 5. Data Groups and Data Points

A new Pleos Vehicle has all Data Points of the five core Data Groups selected (Battery, Powertrain, Driving, Status, Location), including their Report Times, except the map. The other Data Groups start deselected.

| Data Group | Data Points |
|---|---|
| Battery | Battery Report Time, state of charge, charging cable (connected/disconnected), charge status (eight values), charging, battery capacity, charge limit AC and DC, remaining charge time |
| Powertrain | Powertrain Report Time, fuel level, high-voltage battery level, range per range type: EV, ICE, HEV, total and electric range (PHEV), FCEV |
| Driving | Driving Report Time, ignition, ignition (IGN1), high-voltage system (IGN3), accessory power (ACC), sleep mode, odometer, in park position |
| Status | Status Report Time, each door (open, closed, locked), vehicle locked, hood, trunk, sunroof, tire pressure per wheel, climate mode, climate control, blower speed, climate set temperature, windshield defrost, steering wheel heating, seat heating and seat ventilation per seat, outside temperature, number of warnings, one warning per warning code (17) |
| Location | Location Report Time, latitude, longitude, altitude, heading, speed, coordinate system, map (off by default) |
| Freezer | Freezer Report Time, freezer, freezer temperature |
| Trip Powertrain | Trip start and duration, minimum and maximum high-voltage battery voltage, maximum engine oil temperature, maximum engine speed, raw data of the last Trip |
| Trip Speed | Trip start and duration, maximum and average speed, maximum wheel speed per wheel, raw data of the last Trip |
| Trip Driving | Trip start and duration, Trip distance, odometer at Trip end, maximum acceleration and deceleration, maximum yaw rate, maximum steering angle and steering speed, ignition, gear, driver and front passenger seat belt, speed limit assist (two options) at Trip end, raw data of the last Trip |
| Trip Status | Trip start and duration, each door at Trip end, minimum tire pressure per wheel, average outside temperature, light switch at Trip end, low fuel warning at Trip end, raw data of the last Trip |

**Derived Data Points** are computed from other values of the same Data Group:
- Vehicle locked: true when all doors are locked.
- Number of warnings and one boolean per warning code.
- Range per range type. Plug-in hybrids report two PHEV entries; the larger is the total range and the smaller the electric range.
- Charging cable: connected or disconnected.

**Value rules:**
- `null`, `"invalid"` and missing values keep the variable's last valid value.
- Raw tire pressures are decoded by their reported unit; 255 means no value.
- The range is parsed from its string; `"-"` and `"invalid"` mean no value.
- The battery capacity is converted into kWh.

**Map:** a string variable with a web content presentation that embeds OpenStreetMap at the vehicle's position. It is off by default: the position is sent to OpenStreetMap whenever the map is displayed. It shows a map only for WGS84 coordinates.

**Trips:** Trip Data Groups are requested at most once an hour, for up to the last 7 days (2 days if the API refuses the longer range), and only Trips that started after the last processed Trip are used. A Trip request that fails (no answer, server error, rate limit) is repeated at the next poll. Their Data Points describe the last new Trip. The raw data of the last Trip is the Trip's JSON for your own scripts, truncated if it exceeds 64 KiB, and off by default.

### 6. Availability Check

"Run Availability Check" requests every Data Group once, also the deselected ones. It shows per Data Group whether it delivered data ("Delivered"), had no data yet ("No data yet", normal during the first 24 hours), or failed, together with its Report Time; for Trip Data Groups the start of the latest Trip stands in. Each Data Point's latest value appears in the selection lists.

It then ticks a proposal in the selection lists: the Data Points with a valid value. The proposal is not applied: review it and apply to create the variables. Repeat the check at any time, for example after a vehicle software update or after the first 24 hours.

The proposal differs from "every Data Point with a value" in three ways:
- Data Points that are off by default, the map and the raw data of the last Trip, are never proposed; they keep their current tick.
- Data Points that do not fit the vehicle type from the vehicle list, such as the fuel level or the combustion engine range of an electric vehicle, are not proposed, but stay selectable.
- A Report Time, Trip start or Trip duration is not proposed when it is the only valid value of its Data Group: a timestamp alone tells nothing (the Kia EV2, for example, reports a Freezer Report Time without any freezer values).

### 7. Visualization

The tile shows the state of charge, the range, the odometer, the Battery Report Time ("12 min ago") and an icon for the charging cable and charging. It reads the instance's variables, shows "—" for Data Points that are not selected or have no value yet, and follows the light and dark theme. The text grows with the tile size; the font size of the visualization is the lower bound. Its texts appear in the language of the user who views it. The range is the first range Data Point with a value, in the order EV, electric range (PHEV), total range (PHEV), FCEV, HEV, ICE.

### 8. Statuses and messages

| Code | Status | Meaning |
|---|---|---|
| 102 | Active | The vehicle is polled |
| 104 | Inactive | No VIN entered, or the Pleos Account is inactive (switched off or not configured). An inactive Pleos Account is no error; the vehicle follows it when it becomes active again. |
| 201 | No active Pleos Account | The instance is not connected to a Pleos Account, or the Pleos Account reports an error (invalid API Key, access denied). The vehicle shows it as soon as the Pleos Account changes its status and keeps polling, so the Pleos Account can recover. |
| 202 | Vehicle not covered by API Key | The API reports the vehicle as unregistered (4101) or without consent (5006). Issue a new API Key that covers the vehicle. |
| 203 | Pleos Account rate limited | The Pleos API limits the number of requests and the Pleos Account pauses them for a few minutes. The vehicle is polled again afterwards. |

"No data" (4045) for a Data Group keeps its values and sets no error status. Server errors and timeouts leave the status unchanged; the Pleos Account warns about them. When the Pleos Account cannot send a request (no Access Token, rate limited) or the API does not answer, the poll requests no further Data Groups; the next poll tries again.

The message log gets one message each, remembered across restarts:
- "New data available for …" when a deselected Data Point starts delivering values. Repeat the Availability Check to select it.
- "The API delivers the unknown field …" when a Data Group delivers a field the module does not know. Please report it so a module update can support it.
- "The API delivers the value …, which has no caption" when a Data Point with captions (e.g. Charge status) receives a value the module does not know. The variable shows the raw value. Please report it so a module update can support it.

### 9. PHP functions

```php
void PLEOS_Update(int $InstanceID);
```
Requests the latest Vehicle Report of the selected Data Groups and writes the selected Data Points into their variables. The polling timer calls the same function.

```php
void PLEOS_RunAvailabilityCheck(int $InstanceID);
```
Runs the [Availability Check](#6-availability-check). Its result and proposal appear in the open configuration form; it changes no property and no variable.

```php
string PLEOS_ExportDiagnostics(int $InstanceID);
```
Returns the raw responses of the vehicle list and all Data Groups as JSON, with VIN, nickname, latitude, longitude and altitude replaced by placeholders. Share it when asking for support.

```php
string PLEOS_GetVisualizationTile(int $InstanceID);
```
Returns the HTML of the tile. The tile visualization calls it; scripts do not need it.

Example:
```php
PLEOS_Update(12345);
echo PLEOS_ExportDiagnostics(12345);
```
