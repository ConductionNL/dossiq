# Spec delta: archief-edepot-handover-02-retention-trigger

## ADDED Requirements

### Requirement: Nightly detection assigns retention and marks ready cases
The system MUST run a nightly trigger-detection job that determines each closed case's retention period from its `BewaarTermijnRegel` and creates an `OverdrachtTrigger` with the correct `overdrachtDatum` and status.

#### Scenario: Ready and not-yet-due cases get correct triggers
- **GIVEN** rules exist for `omgevingsvergunning` (5yr) and `wmo-aanvraag` (10yr)
- **AND** a case of type `omgevingsvergunning` closed 2021-05-20 and a case of type `wmo-aanvraag` closed 2017-03-10
- **WHEN** the nightly detection job runs on 2026-05-22
- **THEN** the `omgevingsvergunning` case gets an `OverdrachtTrigger` with `overdrachtDatum` 2026-05-20 and status `gereed-voor-overdracht`
- **AND** the `wmo-aanvraag` case gets an `OverdrachtTrigger` with `overdrachtDatum` 2027-03-10 and status `gepland`

#### Scenario: Permanent retention marks ready without a date
- **GIVEN** a rule for `subsidie-verlening` with `bewaartermijnJaren` = "permanent"
- **AND** a closed case of that type
- **WHEN** detection runs
- **THEN** the trigger status is `gereed-voor-overdracht` with no calculated `overdrachtDatum`

### Requirement: Cases without a retention rule are blocked and DIV is notified
The system MUST mark a closed case whose zaaktype has no `BewaarTermijnRegel` as blocked and notify DIV with an actionable message.

#### Scenario: Missing rule blocks the trigger
- **GIVEN** a closed case of unknown type `custom-process` with no matching `BewaarTermijnRegel`
- **WHEN** detection runs
- **THEN** an `OverdrachtTrigger` is created with status `geblokkeerd-geen-regel`
- **AND** `redenBlokkering` = "Geen BewaarTermijnRegel geconfigureerd voor zaaktype 'custom-process'"
- **AND** a DIV medewerker receives a notification instructing them to configure a retentiebesluit for that zaaktype

### Requirement: Active bezwaar/beroep suspends the trigger
The system MUST suspend archival readiness while a case has an active legal procedure and resume it once the procedure ends.

#### Scenario: Suspended then resumed
- **GIVEN** a case with active bezwaar/beroep
- **WHEN** detection runs
- **THEN** the `OverdrachtTrigger` status is `opgeschort-juridische-procedure` with no `overdrachtDatum`
- **AND** when the bezwaar/beroep ends and detection re-runs, `overdrachtDatum` is calculated and status becomes `gereed-voor-overdracht`
