---
status: done
retrofit: true
---

# DSO Omgevingsloket Client Specification

## Purpose

@e2e exclude Backend intake adapter invoked by openconnector; no Playwright UI surface.

Convert an inbound DSO Omgevingsloket `vergunningaanvraag` message — delivered to dossiq by openconnector's DSO adapter (which owns the DSO-LV koppelvlak, mTLS, PKIoverheid, status pushback per its own spec) — into a dossiq `zaak` of type "Omgevingsvergunning" with the right deadline, title, and DSO-specific side records. Dossiq does NOT own the DSO protocol or the back-channel; this spec is deliberately scoped to the intake-adapter slice.

## Requirements

### Requirement: REQ-004: Dossiq receives no DSO verzoek of its own

Dossiq SHALL NOT expose an endpoint that receives a DSO Omgevingsloket verzoek. Integriq owns the STAM koppelvlak, and dossiq makes the case from integriq's mapped `dso_verzoek` (vth-dso-integration).

@e2e exclude backend route table; covered by DsoIntakeHasOnePathTest, no browser surface

#### Scenario: No dossiq route takes a STAM payload
- WHEN the route table of dossiq is read
- THEN no route SHALL name the DSO intake controller or a `/dso/intake` path
