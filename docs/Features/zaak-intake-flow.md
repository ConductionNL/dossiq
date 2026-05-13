# Zaak Intake Flow

The zaak intake flow manages the process of receiving, validating, and registering new cases (zaken) in the system.

## Overview

The intake flow is the entry point for new cases, whether they originate from citizen requests, internal processes, or external system integrations.

## Planned Features

- **Intake form** -- Structured form for capturing initial case information.
- **Case type selection** -- Choose the appropriate zaaktype during intake.
- **Automatic numbering** -- Generate unique case identifiers (e.g., ZAAK-2026-0001).
- **Validation** -- Verify required fields and data quality during intake.
- **Document attachment** -- Upload supporting documents during case creation.
- **Initiator registration** -- Record the person or organization initiating the case.
- **Initial status** -- Automatically set the initial status based on the case type configuration.
- **Deadline calculation** -- Calculate the processing deadline based on the case type's configured processing period.
- **Assignment** -- Optionally assign the case to a handler during intake or route to the werkvoorraad.

## Integration Points

- **DSO Omgevingsloket** -- Receive intake from the national digital permit system.
- **E-mail intake** -- Create cases from incoming email.
- **API intake** -- Accept cases via ZGW-compatible API endpoints.

## Status

This feature is defined in the spec at `openspec/specs/zaak-intake-flow/spec.md` and is planned for future implementation.
