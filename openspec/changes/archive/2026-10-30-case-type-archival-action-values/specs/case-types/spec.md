## ADDED Requirements

### Requirement: Stored legacy archival actions are mapped onto the enum (REQ-CT-ARCH-1)

On upgrade, a result type whose `archivalAction` holds `retain` MUST be
rewritten to `bewaren`, and one holding `destroy` to `vernietigen`. The step
MUST read and write as the system user, MUST leave every other value alone,
and running it again MUST change nothing.

#### Scenario: a result type saved by the retired editor
- GIVEN a result type with archivalAction `destroy`
- WHEN dossiq is upgraded
- THEN its archivalAction reads `vernietigen`

#### Scenario: a second upgrade
- GIVEN every legacy value has been mapped
- WHEN the step runs again
- THEN nothing is written
