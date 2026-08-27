# MijnOverheid Integration

The MijnOverheid integration enables citizens to view their case status and receive notifications through the national MijnOverheid portal.

## Overview

MijnOverheid is the Dutch national government portal where citizens can view their personal government-related information. This integration allows Dossiq to publish case status information to MijnOverheid.

## Planned Features

- **Status publication** -- Publish case status updates to MijnOverheid's Berichtenbox.
- **Case overview** -- Show active and completed cases on the citizen's MijnOverheid page.
- **Notifications** -- Send case notifications via MijnOverheid's notification system.
- **Document sharing** -- Make case documents available through MijnOverheid.
- **DigiD authentication** -- Support DigiD login for citizen-facing interfaces.
- **Logius integration** -- Connect via the Logius infrastructure for MijnOverheid communication.

## Technical Requirements

- Compliance with MijnOverheid's API specifications.
- Secure message delivery via the Berichtenbox standard.
- BSN (Burgerservicenummer) handling for citizen identification.

## Status

This feature is defined in the spec at `openspec/specs/mijn-overheid-integration/spec.md` and is planned for future implementation.
