---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development. 2026-06-13 member 06 completed the genuinely-absent rollback/retry capability — RollbackManager (onIngestionFailure + retryAfterCorrection) and POST /api/archief/triggers/{triggerId}/retry (archief-role + per-trigger IDOR/state guards), reusing ArchivalTriggerService + ProofOfTransferService; PHPUnit + Newman cover it.
---
# archief-edepot-handover Specification

## Purpose
Dossiq's archival/e-Depot handover is executed by OpenRegister's retention, destruction, and e-Depot transfer abstractions (ADR-022). Dossiq contributes zaakgericht domain knowledge declaratively (retention terms per zaaktype via `x-openregister-archival`, selectielijst mapping, TMLO/MDTO field mapping via `tmloDefaults`) and translates Awb lifecycle events into OpenRegister legal holds. Dossiq runs no archival pipeline of its own.

@e2e exclude Retention, destruction, SIP building, e-Depot transfer, durable retry and proof-of-transfer are executed by OpenRegister (ADR-022) and covered by OR's own e2e/Newman/unit suites; the dossiq-side surface is declarative schema config plus a backend legal-hold listener and a fail-closed migration repair step (guarded, unit-covered). There is no dossiq-only browser flow that drives these scenarios without OpenRegister's archival stack installed — mirrors the sla-charts-via-analytics-leaf / stuf-zkn-outbound precedent.
## Requirements
### Requirement: Retention rules MUST be declared, not executed, by dossiq

Dossiq SHALL express per-zaaktype retention (bewaartermijn, selectielijst categorie/versie,
e-Depot bestemming, MDTO version) as `x-openregister-archival` configuration on its schemas,
migrated from the current `bewaarTermijnRegel` data (including municipality edits, not only the
VNG seed defaults). Dossiq SHALL NOT run its own trigger daemon (`ArchivalTriggerService`),
batch service, or retention matching; OR's RetentionService/RetentionEvaluator and destruction
jobs own execution.

#### Scenario: Closed case is nominated by OpenRegister

- **GIVEN** the case schema carries retention config for zaaktype "omgevingsvergunning" (5 years)
- **WHEN** a case of that type is closed and OR's retention evaluation runs
- **THEN** OpenRegister MUST compute the archiefactiedatum and nominate the case in its archival workflow
- **AND** no dossiq service MUST have executed rule matching

#### Scenario: Unconfigured zaaktype surfaces in OR, not in a dossiq blocklist

- **WHEN** a case whose zaaktype has no retention config is closed
- **THEN** the gap MUST surface in OpenRegister's archivist view as unconfigured
- **AND** dossiq MUST NOT maintain a parallel `geblokkeerd-geen-regel` administration

### Requirement: Legal proceedings MUST suspend archival via OR legal holds

When a bezwaar or beroep is registered against a case, dossiq SHALL place a legal hold on the
case via OpenRegister's `LegalHoldService`; when the proceeding reaches its final Awb outcome,
dossiq SHALL release the hold. Suspension enforcement (retention evaluator and destruction jobs
skipping held objects) is OpenRegister's.

#### Scenario: Bezwaar places a hold

- **GIVEN** a closed case nominated for handover
- **WHEN** a bezwaar is registered against it
- **THEN** dossiq MUST place an OR legal hold on the case
- **AND** OR's transfer and destruction processing MUST skip the case while the hold stands

#### Scenario: Final outcome releases the hold

- **WHEN** the bezwaar/beroep chain reaches a final outcome
- **THEN** dossiq MUST release the hold
- **AND** the case MUST re-enter OR's archival evaluation without manual re-nomination

### Requirement: SIP building and submission MUST run through the OpenRegister e-Depot pipeline

Dossiq SHALL delegate MDTO/TMLO metadata generation, SIP packaging, transfer batching, in-flow
retry, status tracking, and audit logging to OpenRegister (`TmloService`,
`Edepot/EdepotTransferService`, `SipPackageBuilder`, `MdtoXmlGenerator`). Dossiq SHALL contribute its zaak→TMLO/MDTO field
mapping as schema/register configuration. The submission boundary SHALL remain pluggable at OR's
`Edepot/Transport/TransportInterface` (mock/log transport by default); binding a real e-Depot test
endpoint is owned by the `external-integrations-test-environments` change. Dossiq's
`BagItBundlerService`, `ArchivalSubmissionRetryService`, `EDepotSubmissionAdapterInterface`, and
`TmloMetadataBuilderAdapterInterface` seams SHALL be retired; BagIt output, durable retry, and
proof-of-transfer records are OR-side deltas (OR-AD-1..3).

#### Scenario: Handover produces an OR-built SIP

- **GIVEN** a case eligible for overbrenging with dossiq's TMLO mapping declared
- **WHEN** OR's e-Depot transfer runs
- **THEN** the SIP (MDTO metadata + documents) MUST be built by OpenRegister
- **AND** submission MUST go through the configured OR transport
- **AND** no dossiq service MUST have bundled or submitted anything

#### Scenario: Transfer state is visible in OR only

- **WHEN** an archivist inspects a running or failed transfer
- **THEN** status, attempts, and audit trail MUST be readable from OpenRegister's surface
- **AND** dossiq MUST NOT persist `overdrachtTrigger`/`overdrachtTransactie`/`overdrachtAuditLog` records

### Requirement: The case page reads the archival facts openregister publishes (REQ-ARCH-10)

The case page SHALL carry an Archiving tab that renders `@self._retention` as
openregister publishes it: the appraisal, the disposal date, the retention
period, the selectielijst row, the nomination block with the rule and the moment
it was written, and the outcome once a reviewer has decided one. The tab SHALL
derive none of these values. A case openregister could not nominate SHALL show
`nomination.unnominatableReason` as the reason, and a case nobody has closed yet
SHALL be drawn as having no nomination rather than as an unnominatable one.

#### Scenario: a closed case shows what it is nominated for and which row decided
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** a closed case whose retention block carries a nomination
- **WHEN** a handler opens the Archiving tab
- **THEN** the appraisal, the disposal date and the selectielijst row SHALL be shown
- **AND** the rule that produced the nomination SHALL be named

#### Scenario: a case nobody could nominate says which source was missing
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** a closed case whose nomination status is `unnominatable`
- **WHEN** a handler opens the Archiving tab
- **THEN** the tab SHALL show the reason openregister recorded
- **AND** it SHALL NOT be drawn the same way as a case with no nomination at all

#### Scenario: a transferred case says so on its own page
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** a case whose retention block carries an outcome of kind `transfer`
- **WHEN** a handler opens the Archiving tab
- **THEN** the transfer list uuid, the moment and the reviewer SHALL be shown

### Requirement: An archivist recomputes a nomination by saying why (REQ-ARCH-11)

The Archiving tab SHALL offer a recomputation to an archivist or an
administrator only, over `POST /archival/objects/{id}/nomination/recompute`. The
reason SHALL be a required field collected before the request is sent, and the
tab SHALL name the person the act will be recorded against. A reader who is
neither archivist nor administrator SHALL see the control disabled with the role
it needs, never hidden.

#### Scenario: the reason is asked for before the request is sent
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** an archivist on the Archiving tab of a closed case
- **WHEN** they open the recompute control with an empty reason
- **THEN** the confirm SHALL be disabled
- **AND** no request SHALL have been sent

#### Scenario: a handler without the role is told which role it needs

- **GIVEN** a handler who is neither archivist nor administrator
- **WHEN** they open the Archiving tab
- **THEN** the recompute control SHALL be shown and disabled
- **AND** it SHALL name the role required

### Requirement: A reviewer's archival items reach them in My Work (REQ-ARCH-12)

My Work SHALL carry the signed-in person's pending archival reviews, read from
`GET /archival/reviews/pending`. The list SHALL be the answer that endpoint
gives and SHALL NOT be a wider list narrowed in the browser. A reviewer SHALL be
able to answer destroy, retain or transfer from it, each with a reason, and
retain SHALL also collect the new archiefactiedatum the endpoint requires.

#### Scenario: a reviewer sees only their own items
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** two reviewers each holding undecided destruction list entries
- **WHEN** one of them opens My Work
- **THEN** they SHALL see their own entries
- **AND** they SHALL NOT see the other reviewer's

#### Scenario: retaining asks for the new date as well as the reason
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** a reviewer answering retain on one of their entries
- **WHEN** the new archiefactiedatum is empty
- **THEN** the confirm SHALL be disabled

#### Scenario: an answered entry leaves the worklist
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** a reviewer with one pending entry
- **WHEN** they answer destroy with a reason
- **THEN** the entry SHALL leave their list without a page reload

#### Scenario: nobody has anything to review

- **GIVEN** a signed-in person with no pending entries
- **WHEN** they open My Work
- **THEN** the archival reviews section SHALL say there is nothing to sign off
- **AND** it SHALL NOT be drawn as a failed read

### Requirement: The reminder frequency is set in dossiq's archival settings (REQ-ARCH-13)

dossiq's archival settings SHALL surface `reviewReminderFrequency` from
openregister's `/api/settings/archival`, as an ISO 8601 duration defaulting to
`P7D`. Writing it SHALL write openregister's setting and SHALL NOT keep a dossiq
copy. A value that is not an ISO 8601 duration SHALL be refused before it is
sent.

#### Scenario: an administrator changes how often a reviewer is reminded
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** an administrator in dossiq's archival settings
- **WHEN** they set the review reminder frequency to `P14D`
- **THEN** openregister's archival setting SHALL hold `P14D`
- **AND** dossiq SHALL store no copy of it

### Requirement: dossiq derives no archival future of its own once openregister can (REQ-ARCH-14)

Deriving a case's archiefnominatie and archiefactiedatum belongs to
openregister. dossiq's `ArchivalNominationDeriver` and
`ArchivalBaseDateResolver` SHALL be retired the moment openregister can nominate
a provider-mode schema, and SHALL stand until then, because removing them while
openregister's trigger cannot fire would leave every closing case with no
archival future at all.

While they stand, each SHALL say in its own file that it stands in for
openregister and name what blocks its removal. A test SHALL fail if either class
loses that note, so the reason cannot quietly become invisible.

#### Scenario: the standing derivation names what blocks its removal
- @e2e exclude a source-level invariant with no browser surface; asserted by PHPUnit

- **GIVEN** `ArchivalNominationDeriver` and `ArchivalBaseDateResolver`
- **WHEN** the architecture test reads them
- **THEN** each SHALL carry the note naming openregister's missing finality answer
- **AND** the test SHALL fail when the note is removed

#### Scenario: dossiq adds no third derivation
- @e2e exclude a source-level invariant with no browser surface; asserted by PHPUnit

- **WHEN** the architecture test scans `lib/` for archiefactiedatum arithmetic
- **THEN** only the two standing classes SHALL do it

### Requirement: The archive marker on a case is openregister's, not a second field (REQ-ARCH-16)

Where the case page shows whether a case is archived, it SHALL read
openregister's own marker, `@self.archived`, which carries who archived it, when
and why. `case.archiveStatus` is ZGW data describing the archive and SHALL NOT
stand in for the marker. When the two disagree the page SHALL say so rather than
choose between them, because choosing hides exactly the case somebody has to
look at.

#### Scenario: the marker is what the page reads
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** a case openregister holds an archive marker for
- **WHEN** a handler opens the Archiving tab
- **THEN** the moment, the person and the reason from the marker SHALL be shown

#### Scenario: a disagreement is reported, not resolved
- @e2e exclude the two states are set by different writers and cannot both be driven from the browser; asserted by the component test

- **GIVEN** a case whose `archiveStatus` says archived and which carries no marker
- **WHEN** a handler opens the Archiving tab
- **THEN** the tab SHALL say the case and openregister do not agree
- **AND** it SHALL NOT present either as the answer

### Requirement: The dossier zip stays a convenience, not a transfer (REQ-ARCH-15)

`DossierZipExporter` SHALL keep building a downloadable dossier and SHALL NOT be
presented as an archival transfer. The transfer of a case to an e-depot SHALL be
the reviewer's recorded decision, read back on the case as
`outcome.transferListUuid`.

#### Scenario: the zip does not claim to archive anything
- @e2e exclude a copy invariant over an existing surface; asserted by the manifest copy test

- **WHEN** the dossier export is offered on a case
- **THEN** its label SHALL describe a download
- **AND** it SHALL NOT use the words archive or transfer

### Requirement: The case names its selectielijst category (REQ-ARCH-17)

The case schema SHALL declare `x-openregister-archival.categoryProperty` naming
the case field `selectionListClass`, so openregister reads a case's selectielijst
category from the case itself. When a case's result is set, by the in-app
closing transition or through the ZGW API, the case SHALL take the result type's
`selectionListClass`. A result type without a class SHALL leave the case's value
alone. The schema SHALL declare no schema-wide `category`, because the class
differs per result type.

#### Scenario: a closing case carries its result type's class
@e2e exclude Closing a case with a classed result needs a selectielijst register on the e2e instance, or OpenRegister refuses the save. Covered by `ArchivalNominationDeriverTest::testTheResultTypesSelectionListClassIsCopiedOntoTheCase` and `CaseArchivalCategoryDeclaredTest::testTheArchivalBlockNamesTheCategoryProperty`.

- **GIVEN** a result type whose `selectionListClass` is a selectielijst resultaat URL
- **WHEN** a case closes with that result
- **THEN** the case SHALL carry the same URL in `selectionListClass`
- **AND** the case schema SHALL name `selectionListClass` as its `categoryProperty`

#### Scenario: a result type without a class leaves the case alone
@e2e exclude A merge rule with no browser surface. Covered by `ArchivalNominationDeriverTest::testAResultTypeWithoutAClassLeavesTheCasesClassAlone`.

- **GIVEN** a case whose `selectionListClass` an administrator set by hand
- **WHEN** it closes with a result type that declares no class
- **THEN** the case SHALL keep the class the administrator set
