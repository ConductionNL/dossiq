## ADDED Requirements

### Requirement: dossiq receives a Woo request in opencatalogi's shape (REQ-WTO-001)

`OCA\Dossiq\Woo\WooRequestIntake` SHALL offer
`receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array`.

It SHALL read the answer keys opencatalogi's intake reads: `requestedInformation` (required),
`requesterName`, `requesterEmail`, `requesterPhone`, `requesterAddress`, and the optional
`channel` (`web`, `email`, `post`, `counter`, `phone`) and `subjectRef`. It SHALL map them onto
the case: `requestedInformation` to `wooRequest.omschrijving` and, cut at 120 characters on a word
boundary, to `wooRequest.onderwerp`; `requesterName` to `wooRequest.verzoekerNaam`;
`requesterEmail` to `wooRequest.verzoekerEmail`; and `channel` to the case `intakeChannel`
(`web` to `website`, `email` to `email`, `post` to `post`, `counter` to `balie`, `phone` to
`phone`). Phone and address SHALL be kept beside the name and e-mail address, as
`wooRequest.verzoekerTelefoon` and `wooRequest.verzoekerAdres` (amended 10 Oct: the Woo case type
seeds no requester role type, and a role requires a participant reference an anonymous requester
does not have). `origin` SHALL be one of
`portal-form` or `opencatalogi`, and both SHALL be added to `WooRequestIntake::ORIGINS` and to the
`wooRequest.origin` enum. For these two origins `subjectRef` SHALL be optional.

It SHALL answer exactly these keys:
`{outcome: 'armed'|'not-armed'|'refused'|'unavailable', requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}`,
where `requestId` is the case uuid, `reference` is the case `identifier`, and `dueAt` is the rolled
deadline as `Y-m-d`. A refusal SHALL store nothing.

`OCA\Dossiq\Portal\PortalContributionProvider` SHALL offer
`receiveWooRequest(array $answers, string $receivedAt = ''): array`, the same name, arguments and
first five return keys as `OCA\OpenCatalogi\Portal\PortalContributionProvider::receiveWooRequest()`,
calling `receive()` with origin `portal-form`.

#### Scenario: An anonymous portal form becomes a Woo case
- **GIVEN** dossiq installed with the Woo request case type and the term engine available
- **WHEN** `PortalContributionProvider::receiveWooRequest(['requestedInformation' => 'Alle adviezen over de Stationsweg 2025', 'requesterName' => 'J. de Vries', 'requesterEmail' => 'j@example.nl'], '2026-11-27T10:15:00+01:00')` is called
- **THEN** it SHALL answer `outcome` `armed`, a `requestId` that is a case uuid, the case `identifier` as `reference`, and `dueAt` `2026-12-28`
- **AND** the case SHALL carry `wooRequest.origin` `portal-form`, `intakeChannel` `website` and `wooRequest.verzoekerEmail` `j@example.nl`

#### Scenario: A request for nothing is refused and stores nothing
- **GIVEN** answers with an empty `requestedInformation`
- **WHEN** `receive()` is called
- **THEN** it SHALL answer `outcome` `refused` with a message saying what is missing, and empty `requestId`, `reference` and `dueAt`
- **AND** no case SHALL have been written

#### Scenario: The same keys on both sides
- **GIVEN** opencatalogi's `receiveWooRequest()` docblock return shape `{outcome, requestId, reference, dueAt, message}`
- **WHEN** dossiq's `receiveWooRequest()` answers any outcome
- **THEN** every one of those five keys SHALL be present and a string

### Requirement: armed means a term runs, counted from when the requester sent it (REQ-WTO-002)

Under Woo art. 4.4 lid 1 and Awb art. 4:1 the term runs from receipt. `receive()` SHALL start the
case on `receivedAt` (falling back to now only when it is empty or unreadable) and SHALL answer
`armed` only when all of these hold after the write: the case has a statutory term instance, the
instance's FlowTimer is armed, and the case `deadline` equals the instance's rolled
`endDateCurrent`. `dueAt` SHALL be that date. When the case was written but any of these fails,
it SHALL answer `not-armed` with the case's `requestId` and `reference`, an empty `dueAt` and the
reason, and the case SHALL carry an internal timeline entry saying the term did not start. When
OpenRegister, the dossiq register, the case type or the term engine is missing, it SHALL answer
`unavailable` and SHALL write nothing.

#### Scenario: The term counts from the moment the requester sent it
- **GIVEN** a portal form sent on 2026-11-27 and delivered by portaliq's job on 2026-11-30
- **WHEN** the delivery calls `receiveWooRequest()` with `receivedAt` 2026-11-27T23:50:00+01:00
- **THEN** the case `startDate` SHALL be 2026-11-27 and `dueAt` SHALL be 2026-12-28

#### Scenario: The requester is told when the clock started
- **GIVEN** a request received through `receiveWooRequest()` with `outcome` `armed`
- **WHEN** the acknowledgement of receipt is sent to the requester
- **THEN** its text SHALL name the receipt date 2026-11-27 and the due date 2026-12-28, the same `dueAt` the intake answered

#### Scenario: A case without a running term is not reported as armed
- **GIVEN** a term engine that refuses to arm the timer
- **WHEN** `receive()` writes the case
- **THEN** it SHALL answer `not-armed` with the case uuid and identifier and an empty `dueAt`
- **AND** the case SHALL carry an internal timeline entry naming the refusal

#### Scenario: No term engine, no case
- **GIVEN** OpenRegister's term engine classes do not resolve
- **WHEN** `receive()` is called with a valid request
- **THEN** it SHALL answer `unavailable` and no case SHALL exist afterwards

### Requirement: The term behaves as opencatalogi's did, proven on its fixtures (REQ-WTO-003)

A parity suite SHALL replay opencatalogi's statutory term scenarios against dossiq's term on a
Woo case, with a fixed clock and a fixed calendar: the start told at intake; a P28D term rolled
under Awt art. 1; one P14D extension with a reason (Woo art. 4.4 lid 2) and a second refused with
nothing changed; a suspension while clarification is awaited (Awb art. 4:15 lid 1 sub a) during
which no due moment passes, and a resume that puts the term back on the clock; and a terms report
that counts `met`, `missed`, `running` and `suspended` and answers no share when nothing is decided.
Every scenario SHALL name the opencatalogi test it mirrors.

#### Scenario: One extension, a second refused
- **GIVEN** a Woo case received 2026-10-05 with `deadline` 2026-11-02
- **WHEN** the handler extends with the reason "Zienswijzen van derden" and then tries again
- **THEN** the first extension SHALL move the deadline to 2026-11-16
- **AND** the second SHALL be refused with HTTP 409 and the deadline SHALL stay 2026-11-16

#### Scenario: A suspension stops the clock and a resume restarts it
- **GIVEN** a running Woo term with `deadline` 2026-11-02 and a requester reachable by e-mail
- **WHEN** the handler asks for clarification on 2026-10-12 and the requester answers on 2026-10-19
- **THEN** while suspended the term SHALL report `suspended` and no reminder or expiry SHALL fire
- **AND** after the resume the `deadline` SHALL be 2026-11-09

#### Scenario: The report counts like opencatalogi's
- **GIVEN** four Woo terms in one quarter: one decided on time, one decided late, one running, one suspended
- **WHEN** the quarterly report is read for that quarter
- **THEN** the Woo case type SHALL report `met` 1, `missed` 1, `running` 1, `suspended` 1 and `metShare` 50.0

#### Scenario: A share of nothing is not full compliance
- **GIVEN** two running Woo terms and none decided in the quarter
- **WHEN** the quarterly report is read
- **THEN** `metShare` for the Woo case type SHALL be null, not 100

### Requirement: Every stored opencatalogi request is imported exactly once (REQ-WTO-004)

The import is the generic `case-record-import` capability (REQ-CRI-001 to REQ-CRI-003, decision
182); what is Woo about it is configuration. The seeded Woo case type (`woo-verzoek`,
`register.d/81-woo-verzoek.json`) SHALL declare one `recordImports` entry, key
`opencatalogi-woo-requests`, that reads every `wooRequest` object in opencatalogi's `publication`
register, and `occ dossiq:case:import-records woo-verzoek` SHALL run it. `unmigrated` SHALL count the
source requests that carry no `migratedTo`.

The declaration SHALL:

1. match an existing case on `wooRequest.originReference` (the source uuid, written with
   `wooRequest.origin` `opencatalogi`), so a second run writes no second case;
2. write `startDate` and `receivedAt` from `receivedAt`, the requester into `wooRequest`
   (`verzoekerNaam`, `verzoekerEmail`, `verzoekerTelefoon`, `verzoekerAdres`), the question into
   `title` and `wooRequest.onderwerp` (cut at 120 characters) and `description` and
   `wooRequest.omschrijving`, `intakeChannel` from `channel` (`web` website, `email`, `post`,
   `counter` balie, `phone`; empty is `other`), and the old reference in `formerReferences`
   (`{application: 'opencatalogi', reference}`), declared searchable;
3. map the status: `received` to "Ontvangst", `in_progress` to "Beoordelen documenten",
   `awaiting_clarification` to "Beoordeling ontvankelijkheid" with the term suspended, `decided` to
   "Afgehandeld" with `endDate` from `decidedAt` and the term closed, and `withdrawn` to "Afgehandeld"
   with the result "Ingetrokken";
4. carry the term from `dueAt` (after its extension and suspensions), `extensionCount` and
   `extensionReason` (REQ-CRI-003; one extension is the case type's `extensionPeriod`, P14D);
5. stamp `migratedTo` (the case uuid) and `migratedAt`, and answer `termTimer` as the source timer.

#### Scenario: A running request moves with its remaining time
- **GIVEN** an opencatalogi `wooRequest` WOO-2026-A1B2C3, `received` on 2026-10-05, `dueAt` 2026-11-02, no extension
- **WHEN** the import runs on 2026-10-20
- **THEN** a Woo case SHALL exist with `startDate` 2026-10-05, `deadline` 2026-11-02 and status "Ontvangst"
- **AND** an armed FlowTimer SHALL fire at the end of 2026-11-02
- **AND** the source SHALL carry `migratedTo` with that case uuid

#### Scenario: An extended, suspended request keeps both
- **GIVEN** a source request with `extensionCount` 1, `extensionReason` "Zienswijzen", status `awaiting_clarification` and `dueAt` 2026-11-23
- **WHEN** the import runs
- **THEN** the case term SHALL show one extension with that reason, status suspended, and `deadline` 2026-11-23
- **AND** a later extension on the case SHALL be refused with 409

#### Scenario: Running the import twice changes nothing
- **GIVEN** an import that already migrated every request
- **WHEN** it runs again
- **THEN** `imported` SHALL be 0, `alreadyImported` SHALL equal the number of requests, no timer SHALL be armed and `unmigrated` SHALL be 0

#### Scenario: A half-finished request is completed, not duplicated
- **GIVEN** a source request whose case was written but whose timer failed to arm in the last run
- **WHEN** the import runs again with the engine available
- **THEN** the existing case SHALL get its timer, the source SHALL be stamped, and only one case SHALL refer to that source

#### Scenario: A citizen's old number still finds the case
- **GIVEN** an imported case whose `formerReferences` holds WOO-2026-A1B2C3
- **WHEN** a handler searches cases for "WOO-2026-A1B2C3"
- **THEN** the case SHALL be in the results

#### Scenario: No opencatalogi, nothing to import
- **GIVEN** opencatalogi is not installed
- **WHEN** `occ dossiq:case:import-records woo-verzoek` runs
- **THEN** it SHALL say opencatalogi is not installed, answer zeros, exit 0 and write nothing

### Requirement: The decision and inventory are drafted from the organisation's templates (REQ-WTO-005)

When the handler creates the Woo decision (POST `/api/cases/{id}/woo/decision`), dossiq SHALL ask
filinq for a besluit draft and an inventory draft through one adapter,
`OCA\Dossiq\Woo\WooDecisionDrafts::draft(string $caseId): array`, which answers
`{status: 'drafted'|'not-drafted', besluitFileId?: int, inventoryFileId?: int, template?: string, reasonCode?: string, reason?: string}`.
The adapter SHALL use the contract filinq's `woo-request-workflow` amendment names: it dispatches
`new \OCA\Filinq\Event\DocumentGenerationRequestedEvent(request: [...], requestingApp: 'dossiq')`
once with `templateSlug` `woo-besluit` and once with `woo-inventarislijst`, each with
`data` `['wooDecision' => ...]` in the shape filinq's spec declares, `object` the case reference and
`format` `pdf`, and then reads `isHandled()`, `getResult()` (`fileId`, `path`, `name`, `mime`,
`size`, `format`, `template`, `object`, `metadata`, `requestingApp`, `warnings`) or `getError()`.
The drafts SHALL be filed as documents on the case. The decision record SHALL be written either
way, and SHALL carry the draft status.

#### Scenario: filinq drafts the besluit
- **GIVEN** filinq installed with an organisation-edited Woo besluit template
- **WHEN** the handler creates the decision on a fully assessed Woo case
- **THEN** a besluit document and an inventory document SHALL be on the case, and the response SHALL say `drafted` with the template filinq used

#### Scenario: The wooDecision has the shape filinq checks
- **GIVEN** a Woo case with one `openbaar`, one `deels_openbaar` and one `niet_openbaar` assessment, the last two with weigeringsgronden
- **WHEN** dossiq builds the `wooDecision` for the draft request
- **THEN** it SHALL carry `reference`, `subject`, `receivedAt`, `decisionDate`, `decisionKind`, `organisation` and three `documents` with unique `inventoryNumber`s, assessments `disclose`, `partially-disclose` and `withhold`, and non-empty `groundCodes` on the last two

### Requirement: Without filinq the decision says no draft was made (REQ-WTO-006)

When the event comes back neither handled nor refused (filinq absent), `draft()` SHALL answer
`not-drafted` with reason code `filinq-missing`. When `getError()` answers, it SHALL answer
`not-drafted` with `filinq-refused` and filinq's error as the reason. It SHALL NOT file a placeholder
document. The decision response and the case page SHALL say that
no draft was generated and why.

#### Scenario: No filinq, no fake letter
- **GIVEN** filinq is not installed
- **WHEN** the handler creates the decision
- **THEN** the decision SHALL be stored, the response SHALL carry `draft.status` `not-drafted` with `filinq-missing`, and no new document SHALL be on the case
