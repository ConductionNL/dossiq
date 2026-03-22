# StUF Protocol Support Specification

## Problem
Procest currently implements ZGW APIs (Zaken, Catalogi, Documenten, Besluiten) for case management via REST controllers (`ZrcController`, `ZtcController`, `DrcController`, `BrcController`). However, many Dutch municipalities still rely on StUF (Standaard Uitwisseling Formaat) -- especially StUF-ZKN (Zaak-Kennis) for case management and StUF-BG (Basis Gemeentelijke) for person/address lookups. This spec defines how Procest supports StUF alongside ZGW, providing a dual API surface over the same OpenRegister case data.
StUF support enables Procest to integrate with legacy form systems (e.g., formulierenmotoren that submit cases via StUF-ZKN), legacy case systems during migration periods, and BRP person lookups via StUF-BG. The approach leverages OpenConnector's existing SOAP infrastructure (SOAPService with StUF-ZKN `edcLk01` awareness) for outbound StUF calls, while adding inbound StUF endpoints to Procest for receiving SOAP messages from legacy consumers.
**Standards**: StUF 3.01, StUF-ZKN 3.10, StUF-BG 3.10, ZGW APIs (VNG), RGBZ, GEMMA
**Feature tier**: V1 (outbound StUF via OpenConnector), V2 (inbound StUF endpoints in Procest)
---

## Proposed Solution
Implement StUF Protocol Support Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the stuf-support specification.

## Success Criteria
#### Scenario STUF-001a: Look up person by BSN
#### Scenario STUF-001b: Person not found in BRP
#### Scenario STUF-001c: StUF-BG fault handling
#### Scenario STUF-001d: Look up person by name and date of birth
#### Scenario STUF-001e: Timeout handling for BRP queries
