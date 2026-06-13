---
status: specified
status-note: "Synced 2026-06-14 from archived consolidation change add-procest-procurement-suite (kind:config). SPEC-COMPLETE; code chain pending (ADR-032). Note: a Supplier Tender schema already exists on development (leverancier-zaakportaal) — the TND code chain MUST reconcile the canonical Tender additively against it."
---

# procest-procurement-tender-management Specification

## Purpose
Define tender (aanbesteding) management for procest: tenders as procest
cases (`schema:Project`) with a complementary `Tender` register, multi-lot
support via deelzaak, declarative lifecycle + termijnen/standstill
calculations, Bids and TenderQuestions as registers, publication via PSI
slots — anchored in Aw 2012 / ARW 2016 (ADR-022/031).
## Requirements
### Requirement: REQ-TND-001 — Tenders SHALL be modelled as procest cases (`schema:Project`), not as a parallel domain object

A tender (aanbesteding, aanbestedingsdossier) MUST be modelled as a
procest `Case` of a seeded `caseType: tender` (Schema.org
`schema:Project`). This reuses procest's existing case-management,
status-transition-engine, role-routing, deadline-tracking, my-work,
doorlooptijd-dashboard, and dashboard capabilities — no new
top-level domain object.

The tender-specific metadata MUST be carried in a complementary
`Tender` register attached one-to-one to the case, holding fields
that don't belong on the generic `Case`:

Schema.org annotation: `schema:Demand` (a structured procurement
solicitation is a `Demand` per Schema.org's commerce vocabulary).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `caseId` | string | Yes | FK to the tender case (one-to-one) |
| `tenderNumber` | string | Yes | Operator-assigned identifier |
| `procedureType` | enum | Yes | `openbaar`, `niet-openbaar`, `mededingingsprocedure-met-onderhandeling`, `concurrentiegerichte-dialoog`, `innovatiepartnerschap`, `onderhandelingsprocedure-zonder-bekendmaking`, `meervoudig-onderhands`, `enkelvoudig-onderhands` (NL Aw 2012 + ARW 2016) |
| `regimeType` | enum | Yes | `europees`, `nationaal-boven-drempel`, `nationaal-onder-drempel`, `sociale-en-andere-specifieke-diensten`, `concessie-werken`, `concessie-diensten` |
| `cpvCodes` | array | Yes | EU Common Procurement Vocabulary codes (8-digit) |
| `nutsCodes` | array | No | NUTS regional codes for delivery location |
| `estimatedValue` | number | No | Excl. BTW; informational — used for drempelbedrag calc |
| `currency` | string | No | ISO 4217 |
| `publicationSource` | string | No | PSI slot (`tenderned-tenders`, `mercell-rfx`, ...) |
| `publicationRef` | string | No | External system reference after publication |
| `lots` | array | No | Per-lot metadata (lots become child cases — REQ-TND-005) |
| `selectionCriteria` | array | No | Per-criterion: label, type, weight, evidence-required (uitsluitingsgronden + geschiktheidseisen) |
| `awardCriteria` | array | No | Per-criterion: label, type (`prijs`, `kwaliteit`, `duurzaamheid`, `levenscycluskosten`), weight, scoringMethod — feeds EVA |
| `timeline` | object | Yes | `publicationDate`, `questionsDeadline`, `bidDeadline`, `awardTargetDate`, `standstillEndDate` (computed — see REQ-TND-006) |
| `state` | enum | Yes | `concept`, `marktconsultatie`, `voorbereiding`, `gepubliceerd`, `inschrijvingen-open`, `beoordeling`, `voorlopige-gunning`, `standstill`, `definitief-gegund`, `ingetrokken`, `mislukt`, `gesloten` |

Statutory framing: Aanbestedingswet 2012 (Aw 2012), Aanbestedingsbesluit,
ARW 2016 (Aanbestedingsreglement Werken). EU Directives 2014/24/EU
(classieke sector), 2014/25/EU (sectoren), 2014/23/EU (concessies).

#### Scenario: A tender is a case in my-work like any other

- **GIVEN** a tender case is created with assignee `inkoper-a`
- **WHEN** that user opens the procest my-work dashboard
- **THEN** the tender case MUST appear with the standard columns; no
  per-tender controller is invoked.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the procest codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `tender_`,
  `aanbesteding_`, or `aankondiging_`
- **THEN** no such classes SHALL exist; all tender data flows through
  the OR object API.

### Requirement: REQ-TND-002 — The `Tender` schema SHALL declare the procurement lifecycle declaratively per ADR-031

The `Tender` schema MUST declare an `x-openregister-lifecycle` block:

| From | To | Trigger | Guard |
|---|---|---|---|
| `concept` | `marktconsultatie` | operator action | none |
| `concept` | `voorbereiding` | operator action | none |
| `marktconsultatie` | `voorbereiding` | operator action | none |
| `voorbereiding` | `gepubliceerd` | operator action | `selectionCriteria` non-empty AND `awardCriteria` non-empty AND `timeline.bidDeadline > today + minimumTermijn(procedureType)` (Aw 2012 art. 2.71 termijnen) AND `publicationSource` set |
| `gepubliceerd` | `inschrijvingen-open` | scheduled at `timeline.publicationDate` | none |
| `inschrijvingen-open` | `beoordeling` | scheduled at `timeline.bidDeadline` | none |
| `beoordeling` | `voorlopige-gunning` | operator action | EVA spec's award decision MUST exist |
| `voorlopige-gunning` | `standstill` | automatic on entry | none |
| `standstill` | `definitief-gegund` | scheduled at `timeline.standstillEndDate` AND no bezwaar pending | none |
| `voorlopige-gunning` | `mislukt` | operator action (after bezwaar succeeds) | bezwaar case MUST be referenced |
| any non-terminal | `ingetrokken` | operator action | reason MUST be captured in audit context |
| `definitief-gegund` | `gesloten` | retention sweep | retention period elapsed |

Per ADR-031, procest MUST NOT author `TenderService::transition*` or
`TenderLifecycleService` methods. Scheduled transitions MUST be backed
by OR `ScheduledWorkflow`.

#### Scenario: A direct write to `state: "definitief-gegund"` is rejected

- **GIVEN** any actor
- **WHEN** they attempt to save a tender with
  `state: "definitief-gegund"` via the generic OR API without going
  through the lifecycle
- **THEN** the save MUST fail with a "lifecycle transition required"
  error.

#### Scenario: Minimum term enforcement on publication

- **GIVEN** an openbaar EU tender with `timeline.bidDeadline = today +
  20 days` (below the 30-day minimum per Aw 2012 art. 2.71)
- **WHEN** the operator triggers `voorbereiding → gepubliceerd`
- **THEN** the transition MUST fail with a guard violation citing the
  applicable Aw article.

### Requirement: REQ-TND-003 — Publication SHALL flow through the PSI `publicationSource` slot, not a hand-rolled TenderNed client

The `voorbereiding → gepubliceerd` transition MUST dispatch the
publication payload via the openconnector source resolved from
`Tender.publicationSource` (per spec
`procest-procurement-system-integration`). Procest MUST NOT author
a `TenderNedClient`, `MercellService`, or any HTTP wrapper.

The publication payload composition (mapping `Tender` fields → eForms
notice XML / TenderNed JSON / Mercell payload) MUST be carried by an
OR mapping declared via `x-openregister-relations` to a
`PublicationPayloadMapping` register or equivalent, NOT by inline
PHP transformation code.

#### Scenario: Reviewer scans for forbidden HTTP

- **GIVEN** the procest codebase
- **WHEN** scanned for `curl_init`, `GuzzleHttp\Client`, hardcoded
  `tenderned.nl`, `mercell.com`, `negometrix.com` URLs in `lib/`
- **THEN** no matches SHALL exist.

### Requirement: REQ-TND-004 — Vragen + Nota van Inlichtingen SHALL be a `TenderQuestion` register, not a free-text field

Operator-supplier Q+A on a tender MUST be modelled as a
`TenderQuestion` register (one record per question) with an
operator-authored `answer` field and a publish flag.

Schema.org annotation: `schema:Question`.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `tender` | string | Yes | FK to the `Tender` UUID |
| `lot` | string | No | Optional FK to a lot (child case) if the question is lot-specific |
| `submittedBy` | string | Yes | Supplier name or anonymised handle (per procedure type) |
| `submittedAt` | datetime | Yes | Auto-set |
| `question` | string | Yes | The supplier's question |
| `answer` | string | No | Operator-authored response |
| `publishedAt` | datetime | No | Set when the answer becomes part of the published Nota van Inlichtingen |
| `noi` | string | No | FK to the published `NotaVanInlichtingen` document URI (docudesk) |
| `state` | enum | Yes | `received`, `under-review`, `answered`, `published`, `rejected` |

Nota van Inlichtingen documents themselves MUST live in docudesk and
be referenced by URI — not stored in procest tables.

#### Scenario: A published NOI surfaces aggregated answers

- **GIVEN** ten `TenderQuestion` records in `state: published` for a
  tender
- **WHEN** the operator generates a Nota van Inlichtingen PDF
- **THEN** the document MUST be authored in docudesk (using docudesk's
  template engine — not in procest's `lib/`), with each question's
  `noi` field pointing back to the resulting URI.

### Requirement: REQ-TND-005 — Multi-lot tenders SHALL reuse procest's existing `deelzaak-support`

When a tender has lots, each lot MUST be modelled as a child case
(deelzaak) under the parent tender case, using procest's existing
`deelzaak-support` capability — `parentCase` references on the
`Case`, with the lot's caseType seeded as `tender-lot`.

Lot-specific Bids, award decisions, and evaluation scoring MUST attach
to the lot's child case, not to the parent. Per-lot aggregations
(received bids, scoring averages) MUST be declared as
`x-openregister-aggregations` over child cases — not as a per-app
`TenderLotService`.

#### Scenario: A bid is recorded against a lot's child case

- **GIVEN** a tender with three lots (three child cases)
- **WHEN** a supplier submits a bid for lot 2
- **THEN** the resulting `Bid` record MUST attach to lot 2's child
  case via `case` ref; the parent tender case aggregations MUST
  surface the new bid count without per-app code.

### Requirement: REQ-TND-006 — Termijnen + standstill SHALL be declarative calculations per ADR-031

The `Tender` schema MUST declare `x-openregister-calculations`
deriving:

- `standstillEndDate` — `voorlopige-gunning timestamp + 20 days` for
  EU regime, `+ 15 days` for nationaal-boven-drempel, none for
  meervoudig/enkelvoudig (Alcatel-termijn / wachttermijn per Aw 2012
  art. 2.127);
- `minimumPublicationTerm` — derived from `procedureType` per Aw 2012
  table;
- `daysUntilDeadline` — `bidDeadline - today` (for dashboard widgets);
- `bezwaarOpen` — boolean, true while any linked bezwaar case (procest
  `bezwaar-lifecycle`) is in a non-terminal state.

Procest MUST NOT author `TenderTermijnService` or
`StandstillCalculator` — calculations are declarative.

#### Scenario: Standstill end is computed from preliminary award

- **GIVEN** an EU openbaar tender, `voorlopige-gunning` set on
  `2026-04-01`
- **WHEN** any read of the tender fires
- **THEN** `standstillEndDate` MUST resolve to `2026-04-21` (20 days
  after, per Alcatel-termijn).

### Requirement: REQ-TND-007 — Bids SHALL be modelled as a `Bid` register attached to the tender (or lot) case

Bids (inschrijvingen) MUST be a `Bid` register; one record per
supplier-tender(-lot) submission.

Schema.org annotation: `schema:Offer`.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `tender` | string | Yes | FK to the `Tender` UUID |
| `lot` | string | No | FK to a lot child case if the bid is lot-specific |
| `case` | string | Yes | FK to the case (parent or lot) — surfaces the bid in case views |
| `supplier` | string | Yes | FK to the `Supplier` UUID |
| `submittedAt` | datetime | Yes | Auto-set on receipt (may be set by openconnector inbound event) |
| `submissionRef` | string | No | External system reference (Mercell bid ID, Negometrix submission ID) |
| `priceAmount` | number | No | Bid price (excl. BTW) where the procedure exposes price |
| `responses` | object | Yes | Per-criterion supplier responses (free-form by criterion key) |
| `documents` | array | No | docudesk URIs of supplier-uploaded bid documents |
| `state` | enum | Yes | `received`, `admissible`, `inadmissible`, `excluded`, `evaluated`, `withdrawn` |
| `admissibilityNotes` | string | No | Operator narrative for the admissibility decision |
| `evaluationScore` | object | No | Per-criterion score from EVA spec (set during `beoordeling`) |

`Bid.state` MUST follow a declarative `x-openregister-lifecycle`;
procest MUST NOT author a `BidLifecycleService`.

#### Scenario: A bid arriving after the deadline is rejected

- **GIVEN** a tender's `inschrijvingen-open` state has elapsed and
  the lifecycle has transitioned to `beoordeling`
- **WHEN** a late `Bid` is POSTed
- **THEN** the lifecycle MUST set state directly to `inadmissible`
  with audit context `"laat ingediend"`.

### Requirement: REQ-TND-008 — Tender notifications SHALL be declarative per ADR-031

The `Tender` schema MUST declare `x-openregister-notifications`
covering:

- `publication.confirmed` — fires on confirmation event from
  `publicationSource`; recipients: inkoper + opdrachtgever.
- `questions.deadline.approaching` — 7d / 2d / on day before
  `timeline.questionsDeadline`; recipients: inkoper.
- `bid.deadline.approaching` — 7d / 2d / on day before
  `timeline.bidDeadline`; recipients: inkoper + opdrachtgever.
- `bid.received` — on each new `Bid`; recipients: inkoper.
- `standstill.elapsed` — at `standstillEndDate`; recipients: inkoper
  + opdrachtgever + juridisch.

Procest MUST NOT author `TenderNotificationService`.

#### Scenario: A late-published NOI does not silence the deadline reminder

- **GIVEN** an operator publishes the Nota van Inlichtingen 3 days
  before `bidDeadline`
- **WHEN** the engine ticks
- **THEN** the `bid.deadline.approaching` 2d notification MUST still
  fire (NOI publication and deadline notifications are independent).

### Requirement: REQ-TND-009 — Tender registers SHALL be reachable through the procest manifest navigation

`src/manifest.json` MUST declare:

- a navigation entry `Procurement > Tenders` (`type: index`) binding
  to the `tender` caseType filter on `Case`;
- a `type: detail` page for individual tender cases, including
  side panels for: `Tender` metadata, lots (child cases), `Bid`
  records, `TenderQuestion` records, timeline (computed dates);
- a navigation entry `Procurement > Bid responses` (`type: index`)
  binding to `Bid`;
- a navigation entry `Procurement > Tender questions` (`type: index`)
  binding to `TenderQuestion`;
- a navigation entry `Procurement > Tender dashboard` rendering
  widgets declared via `x-openregister-widgets` (deadlines next 14
  days, tenders by procedureType, average bids per tender).

All renderers MUST be the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4.

#### Scenario: The tenders index lists case-type tenders only

- **GIVEN** the manifest declares the tenders page with
  `filter: { caseType: ["tender"] }`
- **WHEN** an inkoper opens `/index.php/apps/procest/tenders`
- **THEN** the page MUST render via `CnIndexPage` showing tender
  cases — no other case types appear, no procest-side filter
  controller is invoked.

