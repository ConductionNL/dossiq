---
status: done
---

# MCP Integration Specification

## Purpose

@e2e exclude Backend MCP tool surface; invoked by the AI orchestrator (Hermiq) over JSON-RPC and the chat facade, not via the browser UI.

dossiq writes no MCP tool code of its own. Per ADR-063 OpenRegister is the single MCP registry: dossiq declares a curated, read-only `x-openregister-mcp` dialect on 12 of its schemas, and OpenRegister derives 20 `dossiq.{schema}.{verb}` tools from it. Authorisation is OpenRegister RBAC in the caller's own session. The hand-written provider (`dossiq.listProcesses`, `dossiq.getProcessDetails`) was removed by the `dossiq-mcp-adoption` change; its tools map onto `dossiq.case.search`, `dossiq.case.get` and `dossiq.statusRecord.search`.

## Requirements

### Requirement: REQ-MCP-101 — Curated x-openregister-mcp dialect on exactly 12 schemas

Dossiq MUST declare `x-openregister-mcp` with `enabled: true` on exactly these 12 schemas and on no others: `case`, `caseType`, `statusType`, `statusRecord`, `decision`, `result`, `resultType`, `document`, `caseDocument`, `objectionProceeding`, `deadlineInstance`, `complaint`. (Amended 10 Oct 2026 at build: the draft named `task`, `bezwaar` and `termijnInstance`; `bezwaar` and `termijnInstance` were renamed to `objectionProceeding` and `deadlineInstance` by #849, and the case task moved onto OpenRegister's engine `Task`, which is not a register object and so cannot carry the dialect.) Every other schema owned by Dossiq MUST remain at the dialect default (absent / OFF). The block MUST live inside the schema's `configuration` object, which is where `SchemaDerivedToolProvider::mcpAnnotation()` reads it.

#### Scenario: Only the curated schemas are enabled

- **WHEN** the Dossiq registers are imported into OpenRegister
- **THEN** exactly 12 Dossiq schemas SHALL carry `configuration["x-openregister-mcp"]["enabled"] === true`
- **AND** no other Dossiq schema SHALL carry an `x-openregister-mcp` block

#### Scenario: Personal-data and control-plane schemas stay off

- **WHEN** any schema carrying persoonsgegevens (`brpPerson`, `contactmoment`, `customerContact`, the sociaal-domein set, the zaakportaal set), any tenant/SaaS control-plane schema, any mandate/authorisation schema, or any audit-log schema is inspected
- **THEN** it SHALL carry no `x-openregister-mcp` block and SHALL emit no MCP tool

### Requirement: REQ-MCP-102 — The derived Dossiq tool surface is read-only

Every verb Dossiq declares MUST be `search` or `get`, with `scope: "read"` and `readOnlyHint: true`. Dossiq MUST NOT declare `create`, `update`, or `delete` on any schema. Rationale (design.md §D6): every lawful Dossiq write passes through a service that enforces a state-machine guard, a mandate check, a statutory clock, or an Archiefwet retention rule, and a derived write verb writes straight through `ObjectService`, bypassing all of them.

#### Scenario: No write verb is emitted

- **WHEN** the derived tool list is enumerated for `appId = dossiq`
- **THEN** no tool id SHALL end in `.create`, `.update`, or `.delete`

#### Scenario: Case transitions are not agent-writable

- **WHEN** an agent attempts to advance or close a case
- **THEN** no MCP tool SHALL exist that writes `case.status` directly
- **AND** the agent SHALL be unable to bypass `StatusTransitionService` guard evaluation, `statusRecord` emission, automatic actions, or termijn recalculation

#### Scenario: Cases and dossiers cannot be destroyed by an agent

- **WHEN** an agent attempts to delete a case, decision, or document
- **THEN** no `delete` tool SHALL exist for any Dossiq schema, because destruction (vernietiging) of a zaakdossier is an authorised act governed by the selectielijst (`resultType.archivalPeriod` / `archivalAction`) under the Archiefwet

### Requirement: REQ-MCP-103 — Every declared search filter is a real schema property

Each `search.filters` entry MUST name a property that exists on that schema, because `McpAnnotationValidator::validateFilters()` rejects the schema at import otherwise. The declared filters SHALL be exactly: `case` → `status`, `caseType`, `assignee`, `priority`, `identifier`, `isFinalStatus`; `caseType` → `identifier`, `catalogus`, `isDraft`; `statusType` → `caseType`, `isFinal`; `statusRecord` → `case`, `statusType`; `decision` → `case`, `decisionType`, `decisionDate`; `resultType` → `caseType`, `archivalAction`; `document` → `documentType`, `status`, `confidentiality`; `caseDocument` → `case`, `document`; `objectionProceeding` → `case`, `status`, `objection`; `deadlineInstance` → `case`, `status`, `deadlineDefinition`. `result` and `complaint` declare `get` only and therefore no filters.

#### Scenario: Import accepts every declared filter

- **WHEN** the registers are imported
- **THEN** `McpAnnotationValidator` SHALL return zero errors for every Dossiq schema
- **AND** no `mcp-unknown-filter` / `mcp-filters-not-search` error SHALL be raised

#### Scenario: No identifying property is a filter

- **WHEN** the `case` search filter list is inspected
- **THEN** it SHALL NOT contain `initiatorSourceId`, `initiatorDisplayName`, `initiatorType`, or `requester`
- **AND** an agent SHALL therefore be unable to look up or enumerate cases by BSN or by citizen name

### Requirement: REQ-MCP-104 — Personal-data posture of the enabled set (AVG)

Because the dialect offers no server-side field projection, an enabled schema returns everything it stores; Dossiq MUST therefore constrain exposure by schema and verb. `complaint` MUST declare `get` only — never `search` — because `complaint.complainant` is an embedded citizen record (naam + contactgegevens) and a search would let an agent sweep complainants. `case` MAY declare `search` and `get` despite `initiatorSourceId` potentially carrying a BSN, on the condition of REQ-MCP-103's filter restriction, OpenRegister RBAC in the caller's own session, and the immutable audit trail; this residual risk is recorded, not hidden.

#### Scenario: Complaint cannot be swept

- **WHEN** an agent calls the Dossiq tool surface looking for complaints
- **THEN** only `dossiq.complaint.get` SHALL exist, requiring an id the agent already holds from case context
- **AND** no `dossiq.complaint.search` tool SHALL exist

#### Scenario: BSN is never a lookup key

- **WHEN** an agent supplies a BSN as a search filter on any Dossiq tool
- **THEN** the call SHALL be rejected as an undeclared filter by `SchemaDerivedToolProvider::search()`

### Requirement: REQ-MCP-105 — OpenRegister RBAC is the single authorisation gate

The Dossiq MCP surface MUST delegate all authorisation to OpenRegister RBAC, invoked in the caller's ambient Nextcloud session with no impersonation and no system account — identical to the REST path the Dossiq UI already uses (`/apps/openregister/api/objects` via `useObjectStore`, ADR-022). Dossiq MUST NOT re-implement a per-object ACL in the MCP path. The "cases I work on" question SHALL be served by `dossiq.case.search` with the declared `assignee` filter.

#### Scenario: MCP reads match UI reads

- **WHEN** a non-privileged user invokes `dossiq.case.search`
- **THEN** the result set SHALL be exactly the set of cases that user can already read through the Dossiq UI

#### Scenario: My cases

- **WHEN** an agent is asked which cases the current user is handling
- **THEN** it SHALL call `dossiq.case.search` with `filters: { assignee: <current user id>, isFinalStatus: false }`
