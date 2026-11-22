---
status: done
retrofit: true
---

# StUF Integration Specification

## Purpose

@e2e exclude Pure SOAP/backend integration spec covered by PHPUnit; no Playwright UI surface.

Answer StUF (Standaard Uitwisselings Formaat) 3.01 provider questions from legacy Dutch government back-office systems. Integriq owns the wire: it receives the SOAP envelope, authenticates the sender, dispatches by message type and builds the StUF answer. Dossiq answers the question from its cases through integriq's typed events (decision 199).

## Requirements

### Requirement: Dossiq answers StUF provider questions through integriq

Dossiq SHALL answer integriq's `StufCaseQueryEvent` and `StufPersonQueryEvent` only for an integriq
source a `stufEndpoint` names in `integriqSource`. A case query SHALL be answered by identificatie,
each case carrying the zaaktype the endpoint's `zaaktypeMappings` gives its case type; a query
without an identificatie SHALL be refused. A person query SHALL be answered from the case that names
the BSN as its initiator. `StufCaseMutationEvent` and `StufDocumentMutationEvent` SHALL be refused.
When the case store cannot be read the event SHALL stay unanswered, never answered empty.

#### Scenario: A case is found by its identificatie

- GIVEN a case 2026-0042 whose case type maps to `Omgevingsvergunning kap`
- WHEN integriq asks for identificatie 2026-0042 on the endpoint's source
- THEN dossiq answers one case with that zaaktype and startdatum in StUF date form

#### Scenario: Another app's source

- GIVEN no stufEndpoint names the source
- WHEN the event arrives
- THEN dossiq leaves it unanswered

### Requirement: Provider requests reach integriq on its own URLs

A zaaksysteem SHALL post its StUF provider requests to integriq's
`/api/stuf-zkn/provider/{sourceId}/cases` and `/persons`. Dossiq SHALL NOT serve `/api/stuf/cases`,
`/api/stuf/persons`, `/api/stuf/zaken` or `/api/stuf/personen`: they forwarded to integriq for one
release and are removed.

#### Scenario: The Dutch cases URL is gone

- GIVEN a dossiq release after the forwarding release
- WHEN a zaaksysteem posts a zakLv01 to `/api/stuf/zaken`
- THEN no dossiq route answers it
