# Pleos Configurator

[Deutsche Version](README.md)

The Pleos Configurator of the [Pleos library](../README.en.md) lists the vehicles covered by the API Key of its Pleos Account and creates a Pleos Vehicle instance for each of them on request.

> This project is not affiliated with Hyundai Motor Group, Hyundai, Kia, Genesis or Pleos.

### Contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [PHP functions](#5-php-functions)

### 1. Features

- Lists every vehicle of the API Key with name, model and VIN.
- Creates a Pleos Vehicle instance for a vehicle with one click. The instance is named after the vehicle's nickname or, without one, its model, and gets the VIN preset.
- Shows which vehicles already have an instance, so no duplicates are created.
- Keeps listing an existing Pleos Vehicle instance after its vehicle disappeared from the API Key, under the instance's name. The row is then red, so the stale instance can be found and deleted.
- Lists only Pleos Vehicle instances connected to the same Pleos Account, so several accounts do not get mixed up.
- When the API Key covers no vehicle, it explains the common causes: the key was created by a secondary user instead of the vehicle owner, it was issued before the vehicle was added to the account, or the vehicle's connected services are switched off.
- When the vehicle list cannot be loaded, for example without an active Pleos Account, it says so.

### 2. Requirements

- IP-Symcon 9.0 or later
- An active [Pleos Account](../PleosAccount/README.en.md)

### 3. Installation

Install the library "Pleos" through the Module Store or Module Control, then create the instance under "Configurator instances" → "Pleos Configurator". IP-Symcon connects it to a Pleos Account, creating one if none exists. The instance can be created without any configuration.

### 4. Configuration

The Pleos Configurator has no properties. Its form shows the vehicle list "Vehicles":

| Column | Description |
|---|---|
| Name | For an existing instance its name, otherwise the nickname set in the manufacturer's app or, without one, the model; a newly created instance gets this name |
| Model | The model name, for example "EV2", or the model code ("QV1") if the API sends no model name |
| VIN | The vehicle identification number |

Select a vehicle and press "Create" to create its Pleos Vehicle instance. The vehicle list is requested from the API each time the form is opened.

The Pleos Configurator sets no status of its own.

### 5. PHP functions

The Pleos Configurator exports no functions.
