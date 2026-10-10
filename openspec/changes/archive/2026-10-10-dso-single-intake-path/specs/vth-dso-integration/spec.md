## MODIFIED Requirements

### Requirement: DSO verzoek intake and case creation

The system SHALL auto-create a case from a DSO verzoek that integriq has mapped, filing it on the first case type in the verzoek's `mappedCaseTypes` that resolves, at that type's initial status, and flag the case for manual linking when a BRP lookup fails.

**Spec ref**: REQ-VTH-006-A, REQ-VTH-006-B

@e2e exclude backend listener on an OpenRegister object event; covered by PHPUnit and the live intake run, no browser surface

#### Scenario: Verzoek creates a case

- **WHEN** integriq writes a `dso_verzoek` with status `mapped` and the listener triggers
- **THEN** a case SHALL be created with the case type resolved from `mappedCaseTypes`, the type's initial status, `dsoStatus` "submitted" and the verzoek's reference in `permitApplicationRef`

#### Scenario: BRP lookup failure

- **WHEN** the initiator BRP lookup fails during intake
- **THEN** the case SHALL be flagged "Awaiting manual initiator linking"

## ADDED Requirements

### Requirement: One path turns a DSO verzoek into a case

A DSO verzoek SHALL become a dossiq case only through the listener on integriq's mapped `dso_verzoek`, and one verzoek SHALL make at most one case. Dossiq SHALL expose no endpoint of its own that receives a DSO verzoek.

@e2e exclude backend intake path; covered by DsoIntakeHasOnePathTest and the live intake run, no browser surface

#### Scenario: The same verzoek arrives twice

- **WHEN** the listener sees a second write for a verzoek that already has a case
- **THEN** no second case SHALL be created, and the existing case SHALL be answered

#### Scenario: The former dossiq endpoint is called

- **WHEN** a client posts a STAM payload to `/apps/dossiq/api/vth/dso/intake`
- **THEN** no route SHALL answer it and no case SHALL be created
