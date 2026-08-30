---
status: done
status-note: "Synced 2026-06-14 from archived consolidation change add-dossiq-procurement-suite (kind:config). Capability is SPEC-COMPLETE; register patches + manifest wiring land via per-spec code chains (ADR-032). A supplier-facing slice already exists on development via the leverancier-zaakportaal chain (live capability supplier-portal; Supplier/Supplier Tender/Supplier Contract schemas in lib/Settings/dossiq_register.json) — the SUP code chain MUST extend that Supplier schema additively, not create a parallel one."
---

# dossiq-procurement-supplier-management Specification

## Purpose
Define the supplier (vendor) management surface for dossiq as case
management: suppliers as an OpenRegister `Supplier` register, onboarding
as a `supplier-onboarding` caseType, with declarative qualification,
lifecycle, performance, portal-RBAC, and notifications reusing dossiq's
existing case-management machinery and OR abstractions (ADR-022/031).
## Requirements
### Requirement: REQ-SUP-001 — The system SHALL store suppliers as an OpenRegister-managed `Supplier` register

Suppliers MUST be declared as a register in
`lib/Settings/dossiq_register.json` per ADR-024, with the `Supplier`
schema as the canonical entity. No custom PHP model, no custom
database table, no parallel storage (ADR-022 anti-pattern list
applies). The register is exposed through OpenRegister's generic CRUD
HTTP surface; dossiq adds no per-app `SupplierController` for
basic supplier CRUD.

Schema.org annotation: `schema:Organization` (or
`schema:Person` for individual-trader suppliers — the schema's
`legalForm` field discriminates).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | Yes | Display name (statutaire of handelsnaam) |
| `legalForm` | enum | Yes | `nv`, `bv`, `vof`, `eenmanszaak`, `stichting`, `vereniging`, `cooperatie`, `overheid`, `buitenland`, `anders` |
| `kvkNumber` | string | No | Kamer van Koophandel registration (8 digits) |
| `rsin` | string | No | RSIN (9 digits) — required for NL organisations doing public-sector work |
| `vatNumber` | string | No | EU VAT identifier including country prefix |
| `addresses` | array | Yes | Operator-classified addresses (`registered`, `billing`, `delivery`, `correspondence`) |
| `primaryContact` | string | No | UUID reference to a `contact` (dossiq existing register) |
| `peppolParticipantId` | string | No | Peppol participant identifier for e-invoicing (PPP eForms — also feeds CLM) |
| `qualificationLevel` | enum | Yes | `unqualified`, `provisional`, `qualified`, `preferred`, `excluded` — operator-set via lifecycle transition |
| `qualificationValidUntil` | date | No | Set automatically when transitioning into `qualified` |
| `bankAccounts` | array | No | IBAN + BIC list, validated against IBAN format |
| `onboardingCaseId` | string | No | UUID of the `Case` (dossiq `caseType: supplier-onboarding`) currently progressing this supplier |
| `state` | enum | Yes | `prospect`, `onboarding`, `active`, `suspended`, `excluded`, `archived` (lifecycle field — see REQ-SUP-003) |

Statutory framing: Aanbestedingswet 2012 art. 2.86 (uitsluitingsgronden)
+ EU Directive 2014/24/EU art. 57 (grounds for exclusion) require
suppliers to be qualifiable + auditable + excludable; the schema's
`qualificationLevel` + `state` fields are the data hooks.

#### Scenario: A supplier is created via OR's generic API

- **GIVEN** dossiq is installed and the `Supplier` schema is loaded
- **WHEN** an authenticated `procurement-officer` POSTs a new supplier
  to `/index.php/apps/openregister/api/objects/dossiq/Supplier`
- **THEN** the save MUST succeed via OR's generic endpoint, with no
  dossiq-side controller in the call path.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the dossiq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `supplier_`,
  `vendor_`, or `crediteur_`
- **THEN** no such classes SHALL exist; all supplier data flows
  through the OR object API.

### Requirement: REQ-SUP-002 — Supplier onboarding SHALL be modelled as a dossiq case-type, reusing existing case-management machinery

Dossiq MUST seed a `caseType` named `supplier-onboarding` (Schema.org
`schema:Project`) in `lib/Settings/dossiq_register.json`. The case
type inherits every behaviour from dossiq's existing
`case-management` and `case-types` capabilities — statusType
configuration, role assignment, deadline tracking, document
attachments, dashboard visibility, my-work integration. No new case
plumbing.

The onboarding case carries:

- `caseType: supplier-onboarding`
- `subject`: the prospect supplier's display name
- a `caseObject` link pointing back to the `Supplier` UUID
- standard dossiq fields (`assignee`, `priority`, `deadline`)

Required statusType seed: `intake`, `kyc-screening`,
`qualification-review`, `awaiting-supplier-input`, `approved`,
`rejected`, `expired`. Lifecycle is declared via
`x-openregister-lifecycle` on the `Case` schema for `caseType =
supplier-onboarding` — see REQ-SUP-003.

#### Scenario: A supplier-onboarding case shows up in my-work like any other case

- **GIVEN** a procurement officer is assigned to a supplier-onboarding
  case
- **WHEN** they open the dossiq my-work dashboard (existing
  `my-work` capability)
- **THEN** the case MUST appear with the same columns + actions as
  any other case; no per-supplier-onboarding controller is needed.

#### Scenario: The supplier register is reachable from the onboarding case sidebar

- **GIVEN** an onboarding case carries `caseObject` pointing at a
  `Supplier`
- **WHEN** the operator opens the case detail page (rendered by
  dossiq's existing case-detail renderer)
- **THEN** the linked supplier record MUST appear in the standard
  related-objects sidebar (consumed from OR's `object-interactions`
  per ADR-022).

### Requirement: REQ-SUP-003 — The `Supplier` lifecycle SHALL be declarative per ADR-031

The `Supplier` schema MUST declare an `x-openregister-lifecycle`
block with these states and transitions:

- `prospect` — newly entered, no qualification done
- `onboarding` — an `onboardingCaseId` is set and the case is open
- `active` — qualification approved; can be selected for procurement
- `suspended` — temporarily blocked (e.g. open dispute, missing
  certificate renewal); CLM blocks new contracts; existing contracts
  continue
- `excluded` — permanently blocked per Aw 2012 art. 2.86/2.87
  (uitsluitingsgrond); CLM blocks all new contracts
- `archived` — past retention; read-only

| From | To | Trigger | Guard |
|---|---|---|---|
| `prospect` | `onboarding` | operator creates onboarding case | `onboardingCaseId` MUST resolve to an open case |
| `onboarding` | `active` | onboarding case reaches `approved` status | `qualificationLevel` MUST be ≥ `qualified` |
| `onboarding` | `prospect` | onboarding case `rejected` (recoverable) | none |
| `active` | `suspended` | operator action | reason MUST be captured in transition audit context |
| `suspended` | `active` | operator action | reason MUST be captured |
| `active` | `excluded` | operator action (after legal review case) | `Decision` of type `uitsluitingsbesluit` MUST exist |
| `suspended` | `excluded` | operator action (after legal review case) | same |
| `excluded` | `archived` | retention sweep | retention period elapsed |
| `active` | `archived` | retention sweep | `qualificationValidUntil` lapsed + no open contracts |

Per ADR-031 anti-pattern list, dossiq MUST NOT author a
`SupplierService::transition*` or `SupplierLifecycleService` method.
The lifecycle is the only state machine.

#### Scenario: A direct write to `state: "excluded"` is rejected

- **GIVEN** any actor (operator, integration, API client)
- **WHEN** they attempt to save a supplier with `state: "excluded"`
  via the generic OR API without going through the lifecycle
- **THEN** the save MUST fail with a "lifecycle transition required"
  error.

#### Scenario: Exclusion requires a documented decision

- **GIVEN** an `active` supplier
- **WHEN** an operator triggers the `excluded` transition without
  a referenced `Decision` of type `uitsluitingsbesluit`
- **THEN** the transition MUST fail with a guard violation; the
  audit trail MUST record the failed attempt.

### Requirement: REQ-SUP-004 — Supplier qualification SHALL be a `SupplierQualification` register backed by configurable questionnaires

Supplier qualification SHALL be modelled as a dedicated register, not as Supplier fields.

Qualification activities (KYC, financial-health, references, ISO,
SBB certificates, CO2-prestatieladder) MUST be modelled as a
`SupplierQualification` register, not as Supplier fields. Each
qualification record carries:

Schema.org annotation: `schema:AssessAction`.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `supplier` | string | Yes | FK to the `Supplier` UUID |
| `questionnaire` | string | Yes | FK to a `QualificationQuestionnaire` register record (operator-defined) |
| `responses` | object | Yes | Operator/supplier-supplied answers |
| `supportingDocuments` | array | No | docudesk URIs of evidentiary uploads (certificates, financial statements) |
| `scorecard` | object | No | Per-question score, computed via `x-openregister-calculations` |
| `outcome` | enum | Yes | `pending`, `passed`, `failed`, `conditional` |
| `validUntil` | date | No | Drives `Supplier.qualificationValidUntil` |
| `reviewedBy` | string | No | UID of the qualification reviewer |
| `reviewedAt` | datetime | No | Timestamp |

Questionnaires are themselves a register
(`QualificationQuestionnaire`, Schema.org `schema:Questionnaire`)
with versioned question sets; rates and weights are seed data, not
hard-coded enums (ADR-031).

#### Scenario: A qualification questionnaire is reused across suppliers

- **GIVEN** a `QualificationQuestionnaire` named "ISO 27001 baseline"
- **WHEN** five new suppliers are onboarded
- **THEN** five `SupplierQualification` records MUST exist, all
  pointing at the same questionnaire UUID — no questionnaire content
  is duplicated per supplier.

#### Scenario: An expiring certificate fires a renewal notification

- **GIVEN** a supplier's qualification has `validUntil: 2026-08-01`
  and today is `2026-05-01`
- **WHEN** OR's notification engine evaluates the schema's
  `x-openregister-notifications` block (declared per REQ-SUP-007)
- **THEN** a renewal-reminder notification MUST be dispatched to the
  supplier's primary contact AND to the procurement officer assigned
  to any open contract with this supplier.

### Requirement: REQ-SUP-005 — Supplier performance SHALL be derived via `x-openregister-aggregations`, not authored as a service

Supplier performance SHALL be derived via declarative aggregations and MUST NOT be authored as a PHP service.

Supplier performance scorecards (on-time delivery, defect rate,
SLA adherence) MUST be expressed as aggregations on existing OR
registers (`PurchaseOrder` deliveries from CLM, contract SLA
breaches, customer-contact complaints).

Dossiq MUST NOT author a `SupplierPerformanceService` that loops
PurchaseOrder objects in PHP — per ADR-031 this is the exact
aggregation anti-pattern. The `Supplier` schema's `scorecard`
calculated field reads aggregations declared at the supplier-level:

| Calculation | Source |
|---|---|
| `onTimeDeliveryRate` | aggregation over `PurchaseOrder` lines where `supplier == self.id`: `count(deliveredOnTime) / count(*)` over rolling 12 months |
| `defectRate` | aggregation over `PurchaseOrder.qualityIncidents`: `sum(qty_defect) / sum(qty_delivered)` |
| `slaAdherence` | aggregation over `Contract.slaBreaches` from CLM |
| `complaintCount` | aggregation over dossiq `customerContact` filtered by `supplier == self.id` |

#### Scenario: A scorecard recomputes on the next delivery

- **GIVEN** a supplier with `onTimeDeliveryRate: 0.95`
- **WHEN** a new `PurchaseOrder` delivery is recorded late
- **THEN** the next read of the supplier MUST surface the recomputed
  rate (without a separate "rebuild scorecards" job).

#### Scenario: Reviewer scans for the aggregation anti-pattern

- **GIVEN** the dossiq codebase
- **WHEN** scanned for `class *SupplierPerformanceService*` or
  `class *SupplierScorecardService*`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-SUP-006 — Supplier portal access SHALL flow through OR RBAC, not a parallel auth surface

Supplier portal access SHALL flow through OpenRegister RBAC; dossiq MUST NOT define a parallel auth surface.

External supplier users (representatives logging in to update profile,
upload certificates, accept POs) MUST be modelled as Nextcloud user
accounts in a dedicated user-group (`dossiq-supplier-portal`) and
their per-supplier scope MUST be declared via OR's per-object RBAC
(ADR-022 row "Authorization RBAC"). Dossiq MUST NOT define a
`SupplierPortalAuthService` or store supplier passwords in any
dossiq table.

The `Supplier` schema MUST declare an `x-openregister-authorization`
block restricting:

- portal users to **read** their own `Supplier` record + write a
  whitelisted field subset (addresses, primaryContact,
  peppolParticipantId, bankAccounts),
- write access to `qualificationLevel`, `state`, `onboardingCaseId`
  ONLY to internal `procurement-officer` role,
- read access to other suppliers — forbidden for portal users.

#### Scenario: A portal user updates their own bank account

- **GIVEN** a Nextcloud user in group `dossiq-supplier-portal` linked
  to supplier `S1`
- **WHEN** they PATCH `S1.bankAccounts` via the generic OR API
- **THEN** the save MUST succeed.

#### Scenario: A portal user attempts to read another supplier

- **GIVEN** the same portal user
- **WHEN** they GET `Supplier/S2`
- **THEN** OR's RBAC MUST return 403; no dossiq-side guard runs.

#### Scenario: A portal user attempts to set their own qualificationLevel

- **GIVEN** the same portal user
- **WHEN** they PATCH `S1.qualificationLevel: "preferred"`
- **THEN** the save MUST fail with a per-field RBAC violation.

### Requirement: REQ-SUP-007 — Supplier notifications SHALL be declarative per ADR-031

The `Supplier` and `SupplierQualification` schemas MUST declare
`x-openregister-notifications` blocks covering:

- `qualification.expiring` — fires 90 / 30 / 7 days before
  `qualificationValidUntil`; recipients: supplier primaryContact +
  procurement officer + any officer assigned to open contracts.
- `qualification.expired` — fires on the day; same recipients;
  triggers `Supplier.state` recommendation banner to suspend.
- `state.suspended` — recipients: supplier primaryContact + every
  internal contract owner with an active contract for this supplier.
- `state.excluded` — recipients: supplier primaryContact + every
  internal contract owner + procurement-management group.
- `qualification.outcome` — recipients: supplier primaryContact;
  template differs by `outcome` enum.

Dossiq MUST NOT author a `SupplierNotificationService` — per ADR-031
this is the exact notification anti-pattern.

#### Scenario: An expiring qualification fires three reminders

- **GIVEN** a `SupplierQualification` with `validUntil: 2026-08-01`
- **WHEN** the engine ticks
- **THEN** notifications MUST be dispatched on `2026-05-03`,
  `2026-07-02`, and `2026-07-25` (90/30/7 days prior), each carrying
  the same template body with adjusted urgency.

### Requirement: REQ-SUP-008 — Supplier registers SHALL be reachable through the dossiq manifest navigation

`src/manifest.json` MUST declare:

- a navigation entry `Procurement > Suppliers` with `type: index`
  binding to `Supplier`;
- a `type: detail` page for individual suppliers, including a
  side-panel listing the supplier's `SupplierQualification` records;
- a navigation entry `Procurement > Supplier onboarding` filtered by
  `caseType: supplier-onboarding`, reusing dossiq's existing case
  index renderer;
- a navigation entry `Procurement > Qualification questionnaires`
  (admin-only via the manifest's visibility predicate) bound to
  `QualificationQuestionnaire`.

All renderers MUST be the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4. Dossiq MUST NOT author a per-page
Vue component for any of the above.

#### Scenario: The supplier index lists active suppliers

- **GIVEN** the manifest declares the supplier pages
- **WHEN** a `procurement-officer` opens `/index.php/apps/dossiq/
  suppliers`
- **THEN** the page MUST render via `CnIndexPage` showing the
  organisation's suppliers with columns (name, qualificationLevel,
  state, lastDelivery).

#### Scenario: An admin-only menu entry is hidden for a non-admin

- **GIVEN** a user without the `procurement-admin` role
- **WHEN** they open the dossiq main menu
- **THEN** the `Qualification questionnaires` entry MUST NOT appear
  (per the manifest's visibility predicate).

