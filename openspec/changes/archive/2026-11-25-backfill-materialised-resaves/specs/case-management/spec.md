# case-management delta: backfill-materialised-resaves

## MODIFIED Requirements

### Requirement: Existing cases get the role of their status (REQ-CM-77)
A repair step MUST give `statusRole` (and, through the same base, `caseTypeIdentifier`) to every existing case whose referred row carries a value the case lacks or holds stale. Because the case schema declares these fields `readOnly`, the step MUST NOT supply the value in its write: it MUST re-save the case unchanged and let OpenRegister's save-time calculation materialise it. It MUST write in system context, MUST write nothing else, MUST be safe to run again, and MUST NOT fail the upgrade when one case refuses.

#### Scenario: An instance with cases from before the field
@e2e exclude A repair step, asserted in BackfillCaseStatusRoleTest against a store fake; the coordinator runs it on a live instance.
- **GIVEN** a case saved before `statusRole` existed, in a status whose role is `intake`
- **WHEN** the repair step runs
- **THEN** the case MUST carry `statusRole` `intake`
- **AND** a second run MUST write nothing

#### Scenario: The materialised field is readOnly
@e2e exclude A repair step, asserted in BackfillCaseTypeIdentifierTest against a store that refuses a changed readOnly value and materialises on save, as OpenRegister does.
- **GIVEN** a Woo case without `caseTypeIdentifier`, and a store that refuses "Cannot modify readOnly property: caseTypeIdentifier" when a write changes it
- **WHEN** the repair step runs
- **THEN** the write MUST NOT carry `caseTypeIdentifier`
- **AND** the case MUST carry `caseTypeIdentifier` `woo-verzoek` and count as filled

#### Scenario: One case refuses
@e2e exclude Asserted in BackfillCaseStatusRoleTest.
- **GIVEN** two cases to fill, one of which the store refuses
- **WHEN** the repair step runs
- **THEN** the other case MUST be filled
- **AND** the output MUST count one filled and one failed
