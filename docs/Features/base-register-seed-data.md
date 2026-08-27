# Base Register Seed Data

The base register seed data feature provides pre-configured case types, status types, and role definitions that are automatically imported when Dossiq is first installed.

## Overview

To provide a functional out-of-the-box experience, Dossiq includes seed data that sets up common Dutch government case types and their associated configurations.

## Included Seed Data

### Case Types
- **Bezwaar** -- Objection procedure (openbaar confidentiality).
- **Vergunning** -- Permit application (openbaar confidentiality).
- **Melding** -- Public space report (geheim confidentiality).
- **Vergunning aanvraag** (VRG-001) -- Environmental permit application.

### Status Types
Pre-defined statuses for the standard case lifecycle.

### Role Types
Standard roles such as behandelaar (handler), initiator, and belanghebbende (stakeholder).

### Decision Types
Common decision types for government case processing.

## Import Mechanism

Seed data is imported via the "Re-import configuration" button in the Settings > Case Types page. This reads JSON seed files bundled with the application and creates the corresponding OpenRegister objects.

## Status

This feature is implemented. Seed data is automatically imported on first install and can be re-imported via the admin settings.
