---
status: specified
status-note: "Synced 2026-06-14 from archived consolidation change add-procest-procurement-suite (kind:config). SPEC-COMPLETE; code chain pending (ADR-032). Concrete openconnector source rows for the declared slots land in a separate add-openconnector-eu-procurement-sources change."
---

# procest-procurement-system-integration Specification

## Purpose
Define procest's external-procurement integration posture: procest
declares logical connector slots (TenderNed, Mercell, Negometrix, Peppol,
KvK, e-signature, ...) while OpenConnector owns transport (ADR-019).
Inbound via OR's integration registry, slot mappings as schema metadata,
failures surfaced as procest tasks — zero transport code in procest lib/.
## Requirements
### Requirement: REQ-PSI-001 — Procest SHALL declare logical connector slots; openconnector SHALL own transport

Per ADR-019 + ADR-022, procest MUST NOT author transport code
(`*Client`, `*HttpService`, `curl_init`, `GuzzleHttp\Client`) for any
external procurement system. Procest declares **logical connector
slots** — symbolic names that downstream openconnector source rows
fulfil. The slot is a property of the relevant procest register
(e.g. `Tender.publicationSource: "tenderned-tenders"`); the actual
transport is configured by an operator in openconnector.

The initial slot catalogue (each slot is a symbolic name; concrete
sources land in the separate `add-openconnector-eu-procurement-sources`
change):

| Slot | Direction | Purpose | Consumer register |
|---|---|---|---|
| `tenderned-tenders` | out + in | Publish + retrieve TenderNed aankondigingen | Tender, Award |
| `mercell-rfx` | bidirectional | Mercell RFx events + responses | Tender |
| `negometrix-rfx` | bidirectional | Negometrix RFx events + responses | Tender |
| `e-procurement-be` | bidirectional | Belgian Federal Free Market | Tender, Award |
| `placsp-es` | bidirectional | Spanish Plataforma de Contratación del Sector Público | Tender, Award |
| `peppol-orders` | bidirectional | Peppol BIS 3.0 PO + invoice | Contract, [future] financeq |
| `ghx-orders` | bidirectional | GHX healthcare exchange | Contract |
| `kvk-companies` | in | KvK Handelsregister supplier lookup | Supplier |
| `rgs-coa` | in | Referentie Grootboekschema imports | [future] financeq |
| `e-signature` | bidirectional | Generic e-signature provider | Contract |

#### Scenario: Reviewer scans for forbidden HTTP

- **GIVEN** the procest codebase
- **WHEN** scanned for `curl_init`, `GuzzleHttp\Client`,
  `Http\Client`, or hardcoded `tenderned.nl` / `mercell.com` /
  `negometrix.com` / `peppol.eu` / `ghx.com` URLs in `lib/`
- **THEN** no matches SHALL exist; all transport flows through
  openconnector sources.

#### Scenario: A slot resolves at runtime

- **GIVEN** an operator has registered an openconnector source named
  `tenderned-tenders` of type `tender-publication-platform`
- **WHEN** procest dispatches a publish event for a Tender
- **THEN** the dispatch MUST resolve via OR's `ScheduledWorkflow` →
  openconnector source lookup, with no per-app HTTP client.

### Requirement: REQ-PSI-002 — Inbound integration events SHALL flow through OR's integration registry, not a procest webhook controller

Inbound integration events SHALL flow through OR's integration registry; procest MUST NOT define a webhook controller for external systems.

External systems that push to procest (Mercell bid received, TenderNed
publication confirmation, Peppol invoice forwarded, e-signature
completed, KvK record changed) MUST flow inbound via OR's integration
registry (ADR-019) — the openconnector source's inbound webhook
endpoint, OR's CloudEvent dispatcher, then procest's domain handlers.

Procest MUST NOT define `lib/Controller/*WebhookController.php` for
any of the listed external systems.

#### Scenario: A Mercell bid-received event updates the Tender

- **GIVEN** an operator has configured the `mercell-rfx` openconnector
  source with an inbound webhook
- **WHEN** Mercell POSTs a `bid.received` event to the openconnector
  endpoint
- **THEN** openconnector MUST dispatch a `procurement.bid.received`
  CloudEvent on the OR bus; procest's declarative `Bid` lifecycle
  MUST consume it via `x-openregister-lifecycle.requires` — no
  procest webhook controller is invoked.

### Requirement: REQ-PSI-003 — Connector slot mapping SHALL be declared as schema metadata, not as code

Connector slot mapping SHALL be declared as schema metadata; procest MUST NOT author a connector-registry resolution service.

Each slot's mapping (which procest event triggers which slot, which
CloudEvent type returns) MUST be declared as `x-openregister-relations`
on the relevant register's schema, referencing the slot symbolic name.
Procest MUST NOT author a `ConnectorRegistryService` that hardcodes
the slot-to-source resolution.

#### Scenario: A slot mapping is editable in the register file alone

- **GIVEN** a new external system (e.g. Italian ANAC) needs to be
  wired up
- **WHEN** the operator adds a new slot to the relevant register file
  + registers an openconnector source
- **THEN** no procest PHP code MUST change.

### Requirement: REQ-PSI-004 — KvK supplier lookup SHALL be a declarative source enrichment, not a hand-rolled service

The `Supplier` register's `kvkNumber` field MUST declare an
`x-openregister-calculations` or `x-openregister-enrichment` block
(whichever OR extension currently fits — flag a gap per ADR-031
exception (1) if the latter doesn't exist yet) consuming the
`kvk-companies` slot to populate `name`, `legalForm`, `addresses[type
== registered]`, and the rsin (where derivable) when a fresh
`kvkNumber` is entered.

Procest MUST NOT author a `KvkLookupService` HTTP wrapper.

#### Scenario: Entering a KvK number auto-populates the supplier

- **GIVEN** an operator enters a new supplier with only `kvkNumber:
  "12345678"`
- **WHEN** the save fires
- **THEN** the resulting supplier MUST carry the official `name`,
  `legalForm`, and `addresses[registered]` from the KvK source, with
  the audit trail recording the source and timestamp.

### Requirement: REQ-PSI-005 — Outbound integration failures SHALL surface in procest as task signals, not as silent retries

Outbound integration failures SHALL surface as procest `task` records on the relevant case; procest MUST NOT author a parallel failure-log register.

When an outbound dispatch via an openconnector slot fails terminally
(after openconnector's retry policy), the failure MUST surface as a
procest `Task` (reusing the existing procest `task` register) on the
relevant case (Supplier-onboarding, Contract case, or Tender case)
with title `"Integration failure: <slot>"` and the failure payload
in description.

Procest MUST NOT author a parallel `IntegrationFailureLog` register
— OR's audit trail + the surfaced task carry the operator-visible
narrative.

#### Scenario: A failed TenderNed publication surfaces as a task

- **GIVEN** a Tender case where the operator triggered "publish to
  TenderNed" and openconnector exhausted its retries
- **WHEN** the terminal failure event fires
- **THEN** a task MUST appear on the Tender case, assigned to the
  case's `assignee`, with the failure payload in description.

### Requirement: REQ-PSI-006 — Integration manifest entries SHALL be admin-only and declarative per ADR-024

`src/manifest.json` MUST declare an admin-only navigation entry
`Procurement > Integrations` of `type: custom` that points at OR's
existing integration-registry admin UI (consumed from
`@conduction/nextcloud-vue`'s `CnIntegrationsPage`). Procest MUST NOT
author its own integration-management UI.

The entry's visibility predicate restricts it to the
`procurement-admin` role.

#### Scenario: Non-admin users do not see the Integrations entry

- **GIVEN** a user with role `procurement-officer` (no admin)
- **WHEN** they open the procest main menu
- **THEN** the `Integrations` entry MUST NOT appear.

#### Scenario: Admin users land on the shared integration UI

- **GIVEN** a user with role `procurement-admin`
- **WHEN** they click the `Integrations` entry
- **THEN** the page MUST render `CnIntegrationsPage` from
  `@conduction/nextcloud-vue` filtered to slot types declared by
  procest's procurement suite (no procest-side admin component).

