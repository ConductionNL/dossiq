## ADDED Requirements

### Requirement: A case can wait on another case (REQ-RCL-10)

`CaseRelationService` SHALL accept the relation type `waitsOn` and write
its inverse `blocks` on the other case. The Related tab SHALL show both
directions.

#### Scenario: Link a vergunning to the bezwaar it waits on
@e2e tests/e2e/dependent-term.spec.ts

- **GIVEN** a vergunning case and a bezwaar case
- **WHEN** you relate the vergunning to the bezwaar as waits on
- **THEN** the vergunning's Related tab SHALL show the bezwaar under Waits on
- **AND** the bezwaar's Related tab SHALL show the vergunning under Blocks

### Requirement: A moved term is offered to its dependents (REQ-RCL-11)

When a `deadlineEvent` of kind `verleng` or `pauze` is recorded on a case,
each case that waits on it SHALL receive an engine task for its handler
naming the source case and the days moved, with one action that extends the
dependent's active term by those days with the reason "follows <source>"
through `DeadlineExtensionService`. No term SHALL move without that action.

#### Scenario: The handler is offered the extension
@e2e tests/e2e/dependent-term.spec.ts

- **GIVEN** the vergunning waits on the bezwaar
- **WHEN** the bezwaar's term is extended by 14 days
- **THEN** the vergunning's handler SHALL have a task naming the bezwaar and 14 days
- **AND** the vergunning's term SHALL be unchanged

#### Scenario: Accepting extends with the reason
@e2e tests/e2e/dependent-term.spec.ts

- **GIVEN** that task
- **WHEN** the handler accepts it
- **THEN** the vergunning's `endDateCurrent` SHALL move by 14 days
- **AND** the `deadlineEvent` SHALL carry the reason "follows" and the bezwaar's id

#### Scenario: The ceiling still applies
@e2e exclude covered by DependentTermListenerTest over the extension service stub, refused branch

- **GIVEN** a dependent term at its extension ceiling
- **WHEN** the handler accepts
- **THEN** the extension SHALL be refused with the same status a manual one gets

### Requirement: A case link says what it is called from each side (REQ-RCL-12)

A typed peer relation SHALL be stored once, on the case that declares it, in
the property whose declared relation type names both halves of the pair. The
far side SHALL be read back rather than written, and each side SHALL read the
relation under the half of the pair that belongs to that side. The same type
declared from both cases SHALL be refused.

#### Scenario: the followed case reads "heeft vervolg"

- **GIVEN** case A related to case B as a vervolg
- **WHEN** B's Related cases tab is opened
- **THEN** A SHALL appear under "heeft vervolg" and not under "vervolg op"
- @e2e exclude {needs two related cases in the e2e register and the label pair resolved from the shipped schema; asserted in tests/Unit/Service/CaseRelationServiceTest.php::testEachSideReadsItsOwnHalfOfTheLabelPair}

#### Scenario: the same type from both sides is refused

- **GIVEN** case A related to case B as a vervolg
- **WHEN** B is related to A as a vervolg
- **THEN** the request SHALL be refused as a duplicate

#### Scenario: deleting a case clears the links declared towards it

- **GIVEN** three cases each declaring a bijdrage towards case X
- **WHEN** X is deleted
- **THEN** none of the three SHALL still link to X
- @e2e exclude {a deletion fan-out over the reverse index, driven in tests/Unit/Service/CaseRelationServiceTest.php::testCleanupForDeletedCaseRemovesCounterparts}

#### Scenario: a link written straight onto the field still reads from both ends

- **GIVEN** a relation written directly onto `relatedCases` by the ZGW inbound mapping
- **WHEN** the case is normalised
- **THEN** the link SHALL be readable from the case it names, under the inverse label
- @e2e exclude {the ZGW inbound mapping has no browser path; asserted in tests/Unit/Service/CaseRelationServiceTest.php::testNormalisePromotesDirectWritesIntoTypedLinks}

### Requirement: A sub-case is derived from its parent (REQ-RCL-13)

Creating a sub-case SHALL apply the inheritance `case.parentCase` declares,
filling only the roles the parent actually carries and the child has not set
itself, and SHALL record what the child took. Inheritance SHALL happen once, at
creation. When the inheritance cannot be applied the answer SHALL say so rather
than report a sub-case that inherited nothing.

#### Scenario: a sub-case starts with the parent's confidentiality and handler

- **GIVEN** a confidential case with a handler
- **WHEN** a sub-case is created under it
- **THEN** the sub-case SHALL carry both values and the relation SHALL record that it inherited them
- @e2e exclude {the inheritance is applied inside OpenRegister's derive and is asserted in tests/Unit/Service/Deelzaak/SubCaseDeriverTest.php::testTheChildTakesTheParentsAccessValuesAndItIsRecorded}

#### Scenario: a value the sub-case already carries is its own

- **GIVEN** that parent
- **WHEN** a sub-case is created with a confidentiality of its own
- **THEN** the sub-case SHALL keep its own value and SHALL record no inheritance of it

#### Scenario: an inheritance that could not be applied is reported

- **GIVEN** an instance where OpenRegister's relation service cannot be reached
- **WHEN** a sub-case is created
- **THEN** the sub-case SHALL be created carrying its parent, and the answer SHALL report that no inheritance was applied
- @e2e exclude {a degraded OpenRegister cannot be staged from a browser; asserted in tests/Unit/Service/Deelzaak/SubCaseDeriverTest.php::testAnUnreachableRelationServiceIsReported}
