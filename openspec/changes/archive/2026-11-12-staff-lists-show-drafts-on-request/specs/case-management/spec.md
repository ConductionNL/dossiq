# case-management

## ADDED Requirements

### Requirement: Drafts stay out of the staff lists until a handler asks for them (REQ-CM-79)

No staff list SHALL show a colleague's draft by default; a reader's own drafts stay in their lists, as OpenRegister keeps them. The exclusion is OpenRegister's: a list, a count and a search leave out every object in the status draft unless the query carries `_drafts`. dossiq SHALL spell that exclusion out on no page and in no lens, so that a list and the count beside it cannot disagree.

The Cases page SHALL carry exactly one chip that asks for drafts, With drafts. Its filter SHALL be the open cases (`isFinalStatus: false`, `statusHiddenInLists: false`) with `_drafts: "any"`, which shows drafts beside the rest. It SHALL NOT be the default chip and SHALL sit behind the overflow chip, beside My drafts.

The chip SHALL change what the list shows and never what the reader may read: a draft the reader has no access to stays out of the list with the chip on.

#### Scenario: A colleague's draft is not in the work list
- **GIVEN** a case in the status draft that a colleague saved and the handler may read
- **WHEN** the handler opens Cases under All
- **THEN** the draft is not in the list and not in its count
- e2e: `tests/e2e/cases-with-drafts.spec.ts` "a draft shows under With drafts only"

#### Scenario: The handler asks for drafts
<!-- @e2e exclude Second half of the e2e above, which picks the chip and reads the same list; the query parameter itself is asserted by caseListLenses.spec.js. -->
- **GIVEN** the same draft
- **WHEN** the handler picks With drafts
- **THEN** the list shows the open cases and the draft among them

#### Scenario: The handler's own draft
<!-- @e2e exclude OpenRegister keeps the caller's own drafts in every list (form-destination, "The owner still sees their own drafts"); proven there. dossiq adds no condition to it. -->
- **GIVEN** a draft the handler saved themselves
- **WHEN** the handler opens Cases under All
- **THEN** their own draft is in the list, with or without the chip

#### Scenario: Somebody else's private draft
<!-- @e2e exclude Access rule of OpenRegister (DraftStatusPolicy), not of this chip; proven there. The chip sends one query parameter, asserted by caseListLenses.spec.js. -->
- **GIVEN** a draft the handler may not read
- **WHEN** the handler picks With drafts
- **THEN** that draft is not in the list
