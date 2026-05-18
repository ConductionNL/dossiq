# Spec: procest-procurement-spend-analytics-integration

**Status:** proposed
**Scope:** procest (event emitter), mydash (consumer — separate fleet rollout)
**Tier:** procurement-suite
**Depends on:** procest-procurement-supplier-management, procest-procurement-contract-lifecycle, procest-procurement-tender-management, procest-procurement-evaluation-award, openregister (events + webhooks per ADR-022), [future] financeq (referenced as `[future]`, no live dep)

## ADDED Requirements

### REQ-PSA-001: Procest SHALL emit procurement-domain CloudEvents; mydash SHALL consume them via runtime GraphQL

This spec is a **cross-app contract** spec. Procest emits domain
events; mydash consumes them to render the spend-analytics surface.
Per ADR-024 §10 and `feedback_mydash-no-or-dependency.md`, mydash
MUST NOT declare procest, openregister, or financeq as install-time
dependencies — the consumption is runtime-only via OR's GraphQL
endpoint.

Procest MUST NOT author analytics widgets, dashboards, or KPI
calculations beyond what's needed for the suite's own internal
dashboards (declared in spec-internal `x-openregister-widgets`).
The cross-app analytics surface is mydash's responsibility.

Procest MUST emit CloudEvents (per OR's existing
`events + webhooks` abstraction) on these domain transitions:

| Event type | Source register | Emitted when |
|---|---|---|
| `procurement.tender.published` | Tender | lifecycle `voorbereiding → gepubliceerd` |
| `procurement.tender.awarded` | Tender | lifecycle `standstill → definitief-gegund` |
| `procurement.contract.signed` | Contract | lifecycle `awaiting-signature → signed` |
| `procurement.contract.in-effect` | Contract | lifecycle `signed → in-effect` |
| `procurement.contract.expired` | Contract | lifecycle `in-effect → expired` OR `pending-renewal → terminated` |
| `procurement.contract.renewed` | Contract | lifecycle `pending-renewal → in-effect` with extended `effectiveUntil` |
| `procurement.supplier.qualified` | Supplier | lifecycle `onboarding → active` |
| `procurement.supplier.excluded` | Supplier | lifecycle `active|suspended → excluded` |

Each event MUST carry the OR-canonical CloudEvent envelope (id,
source, specversion, type, subject, time, data) with `data` carrying
the changed object's id + the field delta. Procest MUST NOT author a
parallel event-emitter — the OR engine's notification/event pipeline
is the only path.

#### Scenario: A signed contract emits an in-effect CloudEvent

- **GIVEN** a contract in `signed` state with `effectiveFrom` reached
- **WHEN** the lifecycle transitions to `in-effect`
- **THEN** a `procurement.contract.in-effect` CloudEvent MUST appear
  on the OR event bus carrying the contract id, supplier id, and
  effectiveFrom/effectiveUntil in `data`.

#### Scenario: Reviewer scans for parallel event mechanisms

- **GIVEN** the procest codebase
- **WHEN** scanned for `class *EventEmitter*`, `class *EventDispatcher*`
  in `lib/Service/` (excluding OR/symfony framework code)
- **THEN** no such procest-specific event-machinery classes SHALL
  exist.

### REQ-PSA-002: Procest SHALL expose a procurement GraphQL schema slice via OR's GraphQL abstraction

Mydash's spend-analytics widgets MUST query procest data via OR's
GraphQL endpoint (per ADR-022 row "Schema declarative extensions" +
GraphQL exposure). Procest MUST NOT author a custom REST surface for
mydash — the existing OR GraphQL is the only consumer-side contract.

The procest registers (`Tender`, `Contract`, `Supplier`, `Bid`,
`Evaluation`, `decision` with procurement decisionTypes,
`PublicationNotice`) MUST be GraphQL-queryable with declarative
filters declared in the schema metadata. No bespoke procest GraphQL
resolver code.

#### Scenario: Mydash queries procest contracts via GraphQL

- **GIVEN** mydash issues a GraphQL query for `contracts(state:
  in-effect, supplier: $supplierId) { id, valueAmount, effectiveUntil }`
- **WHEN** the query resolves
- **THEN** the OR GraphQL endpoint MUST serve the response, gated by
  OR RBAC; procest's code path MUST NOT contain a `GraphQLResolver`
  class for these queries.

### REQ-PSA-003: RBAC on the GraphQL contract SHALL be the OR-canonical scope, not a mydash-side bypass

The roles that grant cross-app procurement read access via mydash
MUST be the same OR roles procest uses internally
(`procurement-officer`, `contract-manager`,
`procurement-compliance-officer`, `procurement-admin`). Mydash MUST
NOT bypass scope by injecting a service-account role.

Per `feedback_mydash-no-or-dependency.md`, mydash MAY add a
*display-only* alias for these roles (e.g. show "Spend reader" in
mydash UI), but the underlying OR role check is canonical.

#### Scenario: A user without procurement role gets empty results

- **GIVEN** a mydash user who is not in any procurement role
- **WHEN** they load the spend-analytics widget querying procest
  contracts
- **THEN** the OR GraphQL response MUST be empty (RBAC-filtered);
  mydash MUST surface "no data" without a stack trace.

### REQ-PSA-004: Aggregate spend calculations SHALL forward to `[future]` financeq, not be computed in procest

Where the analytics widget needs actual posted spend (GL postings,
invoiced amounts, paid amounts), the data MUST come from financeq,
NOT from procest. Procest provides the *commitment* side (contract
valueAmount, tender estimatedValue, award value); financeq provides
the *posting* side. Until financeq exists:

- procest MUST mark these analytics gaps as `[future]` in the
  widget definitions emitted to mydash via the manifest;
- mydash MUST render the gap visibly ("Spend data unavailable —
  financeq not yet deployed") rather than silently zero.

This is the same forward-looking pattern shillinq specs use for
`[future]` financeq references.

#### Scenario: A mydash widget renders a `[future]` gap

- **GIVEN** a mydash spend-vs-commitment widget for an `in-effect`
  contract with `valueAmount: 100000`
- **WHEN** the widget renders without financeq deployed
- **THEN** the commitment side MUST display `€100.000` and the spend
  side MUST display the `[future]` gap label — not zero, not blank.

### REQ-PSA-005: Procest MUST NOT ship a spend-analytics manifest entry; mydash owns the surface

`procest/src/manifest.json` MUST NOT declare a `Procurement >
Spend analytics` navigation entry. The spend-analytics surface lives
in mydash (per ADR-024 §10 — mydash is the BI surface for the fleet).

Procest MAY declare a deep-link convention (OR's `deep link registry`)
so mydash widgets can link back to individual procest objects
(contract detail, tender detail, supplier detail). The deep-link
metadata MUST be declared as schema metadata, not as a per-app deep
link controller.

#### Scenario: Reviewer confirms no procest-side analytics page

- **GIVEN** the procest manifest
- **WHEN** scanned for a navigation entry with title containing
  "Analytics", "Spend", "Uitgaven", or "Inkoopdashboard"
- **THEN** no such entry SHALL exist in procest's manifest; cross-app
  analytics is mydash's surface.

#### Scenario: A mydash widget deep-links to a procest contract

- **GIVEN** a mydash spend widget showing the top-10 contracts by
  commitment value
- **WHEN** a user clicks one
- **THEN** the link MUST route to
  `/index.php/apps/procest/contracts/<uuid>` via the OR deep-link
  registry; mydash MUST NOT hard-code the URL.
