## REMOVED Requirements

### Requirement: REQ-001: DSO vergunningaanvraag intake creates dossiq zaak

**Reason**: `DsoIntakeService` was a second STAM receiver beside integriq. Its payload carries no activity reference, so it wrote a case without a case type, and it could make a second case for a verzoek the listener already handled.
**Migration**: send DSO verzoeken to integriq's STAM koppelvlak. Dossiq makes the case from integriq's mapped `dso_verzoek` (vth-dso-integration).

### Requirement: REQ-002: DSO-specific case properties stored as side records

**Reason**: retired with `DsoIntakeService`, which was the only writer of these side records.
**Migration**: the verzoek's details stay on integriq's `dso_verzoek`. The case links to it through `permitApplicationRef`.

### Requirement: REQ-003: Procedure-type deadline duration lookup

**Reason**: retired with `DsoIntakeService`. `DsoCaseService` sets `deadlineDate` from the procedure on the listener path.
**Migration**: none needed.

## ADDED Requirements

### Requirement: REQ-004: Dossiq receives no DSO verzoek of its own

Dossiq SHALL NOT expose an endpoint that receives a DSO Omgevingsloket verzoek. Integriq owns the STAM koppelvlak, and dossiq makes the case from integriq's mapped `dso_verzoek` (vth-dso-integration).

@e2e exclude backend route table; covered by DsoIntakeHasOnePathTest, no browser surface

#### Scenario: No dossiq route takes a STAM payload
- WHEN the route table of dossiq is read
- THEN no route SHALL name the DSO intake controller or a `/dso/intake` path
