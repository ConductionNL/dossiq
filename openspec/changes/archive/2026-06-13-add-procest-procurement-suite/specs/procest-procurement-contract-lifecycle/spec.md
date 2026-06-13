# Spec: procest-procurement-contract-lifecycle

**Status:** proposed
**Scope:** procest
**Tier:** procurement-suite
**Depends on:** case-management, case-types, workflow-engine-abstraction, roles-decisions, parafering-actions (for signature endorsement routes), procest-procurement-supplier-management (Supplier ref), openregister (lifecycle + aggregations + notifications + retention per ADR-022), docudesk (contract documents + signed PDFs), openconnector (e-signature provider + Peppol)

## ADDED Requirements

### Requirement: REQ-CLM-001 — The system SHALL store contracts as an OpenRegister-managed `Contract` register

Contracts MUST be declared as a register in
`lib/Settings/procest_register.json` per ADR-024, with the `Contract`
schema as the canonical entity. No custom PHP model, no custom
database table, no parallel storage (ADR-022 anti-pattern list
applies).

Schema.org annotation: `schema:Action` with `actionType:
schema:OrganizeAction` (a contract is the formalisation of a
multi-party action with obligations). The contract document itself is
`schema:DigitalDocument` and lives in docudesk; the `Contract`
register is the *case-side metadata*.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `title` | string | Yes | Display name |
| `contractNumber` | string | Yes | Operator-assigned identifier, unique per administration |
| `supplier` | string | Yes | FK to `Supplier` UUID |
| `caseId` | string | Yes | FK to the contract-as-case (`caseType: contract-lifecycle`) |
| `contractType` | enum | Yes | `mantelovereenkomst`, `raamovereenkomst`, `nadere-overeenkomst`, `bestelovereenkomst`, `dienstverleningsovereenkomst`, `licentie`, `huur`, `sla`, `dpa` |
| `effectiveFrom` | date | Yes | Start of obligations |
| `effectiveUntil` | date | No | End of fixed term (null = indefinite) |
| `renewalPolicy` | enum | Yes | `none`, `auto`, `manual`, `tacit` (stilzwijgende verlenging — flagged for Wet van Dam compliance) |
| `noticePeriodDays` | integer | No | Required to declare for `auto` and `tacit` policies |
| `valueAmount` | number | No | Total contract value (informational only — `[future]` financeq owns the money side) |
| `currency` | string | No | ISO 4217 |
| `obligations` | array | No | Operator-declared key obligations (free-text) |
| `slaTargets` | array | No | Each: `metric`, `threshold`, `breachConsequence` |
| `signedDocumentRef` | string | No | docudesk URI of the signed PDF (set on `signed` transition) |
| `parafeerrouteId` | string | No | FK to a procest `parafeerroute` for the signature endorsement chain |
| `state` | enum | Yes | `draft`, `negotiation`, `awaiting-signature`, `signed`, `in-effect`, `pending-renewal`, `terminated`, `expired`, `archived` |
| `terminationReason` | string | No | Set on `terminated` transition |
| `renewalRemindersSentAt` | array | No | Audit-trail-readable list of reminder timestamps |

Statutory framing: Wet van Dam (stilzwijgende verlenging) — `tacit`
renewal contracts MUST surface noticePeriod warnings; Aw 2012 art.
2.140 (looptijd raamovereenkomst) — public-sector mantelovereenkomsten
cap at 4 years unless justified.

#### Scenario: A contract is created via OR's generic API

- **GIVEN** procest is installed and the `Contract` schema is loaded
- **WHEN** an authenticated `contract-manager` POSTs a new contract to
  `/index.php/apps/openregister/api/objects/procest/Contract`
- **THEN** the save MUST succeed via OR's generic endpoint, with no
  procest-side controller in the call path.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the procest codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `contract_`,
  `overeenkomst_`, or `mantel_`
- **THEN** no such classes SHALL exist; all contract data flows
  through the OR object API.

### Requirement: REQ-CLM-002 — Each contract SHALL be governed by a `contract-lifecycle` case-type

Procest MUST seed a `caseType` named `contract-lifecycle` (Schema.org
`schema:Project`). Every contract MUST have an associated case
(its `Contract.caseId`); the case is where workflow steps (intake,
risk review, legal review, signature collection, renewal review,
termination) play out using procest's existing
`workflow-engine-abstraction` and `process-step-configuration`
capabilities. No new workflow engine.

The case type ships with a default `workflowTemplate` named
`standard-contract-flow` (declared as data in
`lib/Settings/procest_register.json` seeds — not as PHP). Operators
customise per organisation via the existing visual workflow editor.

#### Scenario: A contract case opens with the seeded workflow

- **GIVEN** the seeded `contract-lifecycle` case type and its default
  workflow template
- **WHEN** a contract manager creates a new contract
- **THEN** an associated case MUST open in the `intake` status with
  the workflow template bound; the contract's `caseId` MUST be set
  before the contract save returns.

### Requirement: REQ-CLM-003 — The `Contract` lifecycle SHALL be declarative per ADR-031

The `Contract` schema MUST declare an `x-openregister-lifecycle`
block:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `negotiation` | operator action | supplier MUST be in state `active` or `prospect` (warning) |
| `negotiation` | `awaiting-signature` | contract case reaches `awaiting-signature` status | `parafeerrouteId` MUST be set; supplier MUST be `active` |
| `awaiting-signature` | `signed` | OpenConnector event from `e-signature` source | `signedDocumentRef` MUST be set |
| `signed` | `in-effect` | scheduled — when `today >= effectiveFrom` | none (automatic) |
| `in-effect` | `pending-renewal` | scheduled — when `today >= effectiveUntil - noticePeriodDays` AND `renewalPolicy != none` | `renewalPolicy` MUST be set |
| `pending-renewal` | `in-effect` | operator action (renewal approved) | new `effectiveUntil` set |
| `pending-renewal` | `terminated` | operator action (renewal declined) | `terminationReason` MUST be set |
| `in-effect` | `terminated` | operator action (early termination) | `terminationReason` MUST be set |
| `in-effect` | `expired` | scheduled — when `today > effectiveUntil` AND no renewal triggered | none |
| `terminated` | `archived` | retention sweep | retention period elapsed |
| `expired` | `archived` | retention sweep | retention period elapsed |

Per ADR-031, procest MUST NOT author `ContractService::transition*`
or `ContractRenewalService` methods.

The scheduled transitions (`signed → in-effect`, `in-effect →
pending-renewal`, `in-effect → expired`) MUST be backed by OR's
`ScheduledWorkflow` per ADR-031 §"Background jobs that orchestrate
external systems" — not by a per-app `OverdueContractsJob`.

#### Scenario: A direct write to `state: "signed"` is rejected

- **GIVEN** any actor
- **WHEN** they attempt to save a contract with `state: "signed"` via
  the generic OR API without going through the lifecycle
- **THEN** the save MUST fail with a "lifecycle transition required"
  error.

#### Scenario: An expired tacit contract is auto-renewed only if policy allows

- **GIVEN** a contract with `renewalPolicy: "tacit"`,
  `effectiveUntil: 2026-12-31`, `noticePeriodDays: 60`
- **WHEN** the date reaches `2026-11-01`
- **THEN** the contract MUST transition to `pending-renewal` AND
  three notifications (REQ-CLM-006) MUST fire before `2026-12-31`.

### Requirement: REQ-CLM-004 — Signature collection SHALL be delegated to OR's e-signature integration (ADR-019)

The `awaiting-signature → signed` transition MUST consume an
OpenConnector source named `e-signature` (ADR-019 pluggable
integration). Concrete provider rows (DocuSign, ValidSign, KSeF, native
Nextcloud PDF sign, ...) land via a separate openconnector change.
Procest MUST NOT author a `DocuSignClient`, `ValidSignService`, or
any signature HTTP wrapper — that is the ADR-019 anti-pattern.

The signing-package itself uses procest's existing
`parafering-actions` capability: the contract's `parafeerrouteId`
declares an endorsement route over internal signers (CFO, juridisch,
inkoper) before the supplier-side e-signature step. The external
e-signature event closes the route.

#### Scenario: A signed event from OpenConnector closes the route

- **GIVEN** a contract in `awaiting-signature` whose parafeerroute has
  collected all internal signatures and the supplier has signed via
  the configured e-signature provider
- **WHEN** the provider emits the `signed` CloudEvent through the
  OpenConnector source
- **THEN** the contract MUST transition to `signed`, the
  `signedDocumentRef` MUST be set from the event payload, and the
  audit trail MUST record both the route closure and the external
  signature event.

#### Scenario: Reviewer scans for forbidden HTTP

- **GIVEN** the procest codebase post-implementation
- **WHEN** scanned for `curl_init`, `GuzzleHttp\Client`, or hardcoded
  `docusign.net` / `validsign.eu` URLs in `lib/`
- **THEN** no matches SHALL exist (the openconnector source is the
  only path).

### Requirement: REQ-CLM-005 — Contract documents SHALL live in docudesk, referenced by URI

Contract documents SHALL live in docudesk and MUST be referenced from the `Contract` register by URI only.

The signed PDF, the negotiated drafts, the supplier's countersigned
copy, and any annexes MUST be stored in docudesk and referenced from
the `Contract` register by URI (per ADR-022 — docudesk owns documents).

Procest MUST NOT define a `lib/Service/ContractDocumentService.php`
that stores PDF bytes in its own table — that is the parallel-storage
anti-pattern.

#### Scenario: The signed PDF is fetched via docudesk

- **GIVEN** a `signed` contract
- **WHEN** an operator opens the contract detail page
- **THEN** the document MUST be fetched from `docudesk` via the URI in
  `signedDocumentRef`; procest's code path MUST NOT contain the PDF
  bytes.

### Requirement: REQ-CLM-006 — Contract notifications SHALL be declarative per ADR-031

The `Contract` schema MUST declare `x-openregister-notifications`
covering at minimum:

- `renewal.upcoming` — fires at `effectiveUntil - noticePeriodDays`,
  again 30 days before, again 7 days before, again on the day;
  recipients: contract owner + procurement-management group.
- `renewal.window-missed` — fires the day after `effectiveUntil` if
  the contract has not transitioned out of `pending-renewal`;
  recipients: contract owner + procurement-management group + (for
  `tacit` policy) compliance-officer group.
- `sla-breach` — fires when an `slaTargets` threshold is breached
  (calculated from delivery + customer-contact aggregations);
  recipients: contract owner.
- `termination` — fires on `terminated`; recipients: supplier
  primaryContact + contract owner + procurement-management group.

Procest MUST NOT author `ContractNotificationService` — per ADR-031
this is the exact notification anti-pattern.

#### Scenario: A renewal-window-missed notification fires once

- **GIVEN** a contract in `pending-renewal` with `effectiveUntil:
  2026-12-31`
- **WHEN** the date becomes `2027-01-01` and no renewal transition
  has occurred
- **THEN** exactly one `renewal.window-missed` notification MUST be
  dispatched (the engine's idempotency MUST prevent duplicates).

### Requirement: REQ-CLM-007 — Contract analytics SHALL be derived via `x-openregister-aggregations` and exposed via widgets

Contract analytics SHALL be derived via declarative aggregations and widgets; procest MUST NOT author an analytics service.

Common contract dashboards (open contracts by supplier, expiring in
next 90 days, value at risk, renewal-policy mix) MUST be expressed as
`x-openregister-aggregations` + `x-openregister-widgets` blocks on
the `Contract` schema.

Procest MUST NOT author `ContractAnalyticsService` or
`ContractStatsService`.

The widgets are consumed by procest's existing dashboard
capability — no per-widget Vue component is needed.

#### Scenario: A dashboard widget reads aggregations directly

- **GIVEN** the seeded `contracts-expiring-soon` widget
- **WHEN** the dashboard renders
- **THEN** the widget MUST display the count of contracts with
  `effectiveUntil < today + 90` AND `state IN (in-effect,
  pending-renewal)`, computed via aggregation — no per-app code path.

### Requirement: REQ-CLM-008 — Contract registers SHALL be reachable through the procest manifest navigation

`src/manifest.json` MUST declare:

- a navigation entry `Procurement > Contracts` with `type: index`
  binding to `Contract`;
- a `type: detail` page for individual contracts, including side
  panels for: linked supplier, parafeerroute progress (reusing
  existing parafering UI), linked obligations + SLAs, document
  attachments (from docudesk via OR `object-interactions`);
- a navigation entry `Procurement > Renewals` filtered to
  `state IN (pending-renewal)`;
- a navigation entry `Procurement > Contract dashboard` rendering the
  widgets declared in REQ-CLM-007.

All renderers MUST be the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4.

#### Scenario: The renewals page lists pending-renewal contracts

- **GIVEN** the manifest declares the renewals page with
  `filter: { state: ["pending-renewal"] }`
- **WHEN** a contract manager opens
  `/index.php/apps/procest/contracts/renewals`
- **THEN** the page MUST render via `CnIndexPage` showing only
  contracts whose state matches the filter — no procest-side filter
  controller is invoked.
