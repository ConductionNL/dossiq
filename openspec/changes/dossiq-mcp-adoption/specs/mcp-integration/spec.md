# MCP Integration — ADR-063 declarative adoption (delta)

## Purpose

@e2e exclude Backend MCP tool surface; invoked by the AI orchestrator (Hermiq) over JSON-RPC and the chat facade, not via the browser UI.

Replace Dossiq's hand-written `IMcpToolProvider` with the ADR-063 declarative surface: a curated `x-openregister-mcp` dialect on 13 of Dossiq's 155 schemas, from which OpenRegister derives 22 read-only `dossiq.{schema}.{verb}` tools. No Dossiq MCP tool code survives.

## ADDED Requirements

### Requirement: REQ-MCP-101 — Curated x-openregister-mcp dialect on exactly 13 schemas

Dossiq MUST declare `x-openregister-mcp` with `enabled: true` on exactly these 13 schemas and on no others: `case`, `task`, `caseType`, `statusType`, `statusRecord`, `decision`, `result`, `resultType`, `document`, `caseDocument`, `bezwaar`, `termijnInstance`, `complaint`. Every other schema owned by Dossiq (142 of 155, including all of `ori_register.json`) MUST remain at the dialect default (absent / OFF). The block MUST live inside the schema's `configuration` object, which is where `SchemaDerivedToolProvider::mcpAnnotation()` reads it.

#### Scenario: Only the curated schemas are enabled

- **WHEN** the Dossiq registers are imported into OpenRegister
- **THEN** exactly 13 Dossiq schemas SHALL carry `configuration["x-openregister-mcp"]["enabled"] === true`
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

Each `search.filters` entry MUST name a property that exists on that schema, because `McpAnnotationValidator::validateFilters()` rejects the schema at import otherwise. The declared filters SHALL be exactly: `case` → `status`, `caseType`, `assignee`, `priority`, `identifier`, `isFinalStatus`; `task` → `status`, `isTerminalStatus`, `case`, `assignee`, `dueDate`, `priority`; `caseType` → `identifier`, `catalogus`, `isDraft`; `statusType` → `caseType`, `isFinal`; `statusRecord` → `case`, `statusType`; `decision` → `case`, `decisionType`, `decisionDate`; `resultType` → `caseType`, `archivalAction`; `document` → `documentType`, `status`, `confidentiality`; `caseDocument` → `case`, `document`; `bezwaar` → `case`, `status`, `objection`; `termijnInstance` → `zaak`, `status`, `termijnDefinitie`. `result` and `complaint` declare `get` only and therefore no filters.

#### Scenario: Import accepts every declared filter

- **WHEN** the registers are imported
- **THEN** `McpAnnotationValidator` SHALL return zero errors for every Dossiq schema
- **AND** no `mcp-unknown-filter` / `mcp-filters-not-search` error SHALL be raised

#### Scenario: No identifying property is a filter

- **WHEN** the `case` search filter list is inspected
- **THEN** it SHALL NOT contain `initiatorSourceId`, `initiatorDisplayName`, `initiatorType`, or `requester`
- **AND** an agent SHALL therefore be unable to look up or enumerate cases by BSN or by citizen name

### Requirement: REQ-MCP-104 — Personal-data posture of the enabled set (AVG)

Because the dialect offers no server-side field projection, an enabled schema returns everything it stores; Dossiq MUST therefore constrain exposure by schema and verb. `complaint` MUST declare `get` only — never `search` — because `complaint.klager` is an embedded citizen record (naam + contactgegevens) and a search would let an agent sweep complainants. `case` MAY declare `search` and `get` despite `initiatorSourceId` potentially carrying a BSN, on the condition of REQ-MCP-103's filter restriction, OpenRegister RBAC in the caller's own session, and the immutable audit trail; this residual risk is recorded, not hidden.

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

## REMOVED Requirements

**Reason (all):** ADR-063 (hydra #102) makes OpenRegister the single MCP registry. Apps MUST NOT hand-write MCP tool code, and a hand-written tool takes precedence over its derived twin — so leaving `DossiqToolProvider` in place would permanently shadow `dossiq.case.search` / `dossiq.case.get` and render the declared dialect inert. Both of its tools are derivable CRUD (design.md §D3), so the class retains zero tools and is deleted outright; no `#[McpTool]` service method and no `IMcpScannableServices` opt-in is created, because nothing survived the surgery.

**Migration (all):** `dossiq.listProcesses(limit?, status?)` → `dossiq.case.search` (same `status` filter, five more, real pagination). `dossiq.getProcessDetails(id|uuid)` → `dossiq.case.get` followed by `dossiq.statusRecord.search(filters: { case: <uuid> })` for the transition history. Hermiq resolves tools from the registry at turn time, so no consumer change is required.

### Requirement: REQ-001 — Implement IMcpToolProvider with stable app id and hardcoded tool catalogue

**Reason:** `lib/Mcp/DossiqToolProvider.php` is deleted, together with its `'mcpProvider' => DossiqToolProvider::class` registration in `lib/AppInfo/Application.php`, its unit test, and the `IMcpToolProvider` test stub. The tool catalogue is now derived by OpenRegister from the schema dialect.

**Migration:** tool ids move from the `dossiq.<verb>` shape to the ADR-063 `dossiq.{schema}.{verb}` shape.

### Requirement: REQ-002 — listProcesses tool with bounded limit and optional status filter

**Reason:** derivable CRUD. The handler was a `case` search with a `status` filter and a hard 20-item truncation.

**Migration:** `dossiq.case.search`, which offers the same `status` filter plus `caseType`, `assignee`, `priority`, `identifier` and `isFinalStatus`, and returns `page` / `pageSize` / `total` / `hasMore` instead of a silent cap.

### Requirement: REQ-003 — getProcessDetails tool returning case + history

**Reason:** derivable CRUD (composite). The handler was a `case` find plus a `statusRecord` search plus a `usort` — a second query, not domain logic.

**Migration:** `dossiq.case.get` + `dossiq.statusRecord.search(filters: { case: <uuid> })`.

### Requirement: REQ-004 — Per-object authorisation (assignee / role / admin) inside invokeTool

**Reason:** `canReadCase()` was an MCP-only ACL that exists nowhere else in Dossiq — the Vue frontend reads cases straight from OpenRegister, so OpenRegister RBAC is already the app's effective access model. Retaining it would require retaining a hand-written provider, which is precisely what shadows the derived surface. Per ADR-063, OpenRegister RBAC is the authoritative gate. This widens the MCP read surface from assignee-scoped to RBAC-scoped, bringing it in line with the UI.

**Migration:** REQ-MCP-105. The assignee-scoped question is served by `dossiq.case.search(filters: { assignee })`.

### Requirement: REQ-005 — Standard error envelopes and result-cap

**Reason:** `errorEnvelope()` and `ITEMS_CAP` were provider-local conventions. `SchemaDerivedToolProvider` owns error shape, string truncation, and bounded pagination (`DEFAULT_PAGE_SIZE` / `MAX_PAGE_SIZE`) for every derived tool in the fleet.

**Migration:** none required — the orchestrator consumes OpenRegister's uniform tool-result shape.
