# Pleos Account

[Deutsche Version](README.md)

The Pleos Account is the splitter of the [Pleos library](../README.en.md).
It holds the Brand and the API Key of one Pleos Playground account, owns the single Access Token of that key and sends every request of its Pleos Configurator and Pleos Vehicle instances to the Pleos Vehicle Data API, one after another.

> This project is not affiliated with Hyundai Motor Group, Hyundai, Kia, Genesis or Pleos.

### Contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [Statuses](#5-statuses)
6. [PHP functions](#6-php-functions)

### 1. Features

- Fetches the Access Token with the API Key and reuses it.
  When the API rejects it (HTTP 401 or 403), the Pleos Account fetches a new one once and repeats the request once.
- Sends all requests one after another, so several vehicles on one API Key never revoke each other's Access Token.
- Honors a rate limit (HTTP 429): all requests pause until the time in `Retry-After`, at least 5 minutes, then the status returns to active by itself.
- Server errors and timeouts (30 s) cause no error status; the request is repeated at the next poll.
  The message log gets one warning per outage, not one per failed request.
- When the API refuses the token request without blaming the credentials (e.g. HTTP 403), the status stays active.
  The message log gets one warning until an Access Token arrives again.
  Only HTTP 400, HTTP 404 with code 40404 and an answer without an Access Token count as an invalid API Key (status 201); the message log then gets one error when the status changes.
- After a failed token request, the next one follows after 60 seconds at the earliest.
  Requests in that time are not sent, e.g. during a diagnostics export.
  "Test connection" and changed settings ask at once.
- The Client Secret, the Access Token and the Authorization header never appear in debug output or the message log; VINs are shortened to their first 3 and last 4 characters.

**Use the API Key in this Pleos Account only.**
The API accepts one Access Token per API Key at a time.
If evcc, Home Assistant, another Pleos Account or any other client uses the same API Key at the same time, the clients revoke each other's Access Token and this Pleos Account ends up in the status "Access denied".

**Moving the API Key between two Symcon systems**, such as a test and a production system: clear "Active" in the Pleos Account on the one system, then set it on the other.
A switched-off Pleos Account sends nothing to the API and keeps its settings.
The Pleos Account switched on fetches a new Access Token with its next request, which revokes the token of the other system.

### 2. Requirements

- IP-Symcon 9.0 or later
- An API Key from the Pleos Playground, see [Getting an API Key](../README.en.md#5-getting-an-api-key)

### 3. Installation

Install the library "Pleos" through the Module Store or Module Control.
A Pleos Account is usually created together with the first Pleos Configurator; it can also be created on its own under "Splitter instances" → "Pleos Account".
An instance can be created without any configuration; it stays inactive until Brand, Client ID and Client Secret are entered.

### 4. Configuration

| Property | Description |
|---|---|
| Active | Set by default. Cleared, the Pleos Account sends no requests to the API, and its vehicles become inactive and keep their last values. |
| Brand | Hyundai, Kia or Genesis: the Brand the API Key was issued for. Must be selected. |
| Client ID | The Client ID of the API Key |
| Client Secret | The Client Secret of the API Key, entered in a password field |

| Action | Description |
|---|---|
| Test connection | Fetches a new Access Token and reports whether the API Key and the Brand work |

### 5. Statuses

| Code | Status | Meaning |
|---|---|---|
| 102 | Active | The API accepts the API Key |
| 104 | Inactive | "Active" is cleared, or Brand, Client ID or Client Secret is missing |
| 201 | Invalid Client ID, Client Secret or Brand, or expired API Key | The token request failed. Check the entries; if they are right, issue a new API Key in the Pleos Playground. |
| 202 | Access denied: the API Key may be used by another client or revoked | The API rejected a freshly fetched Access Token. Make sure no other client uses the API Key, or issue a new one. |
| 203 | Rate limited: requests are paused for a few minutes | The API limits the number of requests. The status clears by itself after the pause. |

### 6. PHP functions

```php
string PLEOS_TestConnection(int $InstanceID);
```
Fetches a new Access Token at once, even during the 60-second pause after a failed token request, and returns a message telling whether the connection works.
It sets the status to active or, for an invalid API Key, to 201.
A switched-off Pleos Account sends nothing and only reports that it is switched off.

Example:
```php
echo PLEOS_TestConnection(12345);
```

The Pleos Account exports no other functions.
Pleos Configurator and Pleos Vehicle send their requests through the data flow.
