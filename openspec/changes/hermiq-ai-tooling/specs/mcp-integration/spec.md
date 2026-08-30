# MCP Integration — curated Hermiq AI tooling (delta)

## Purpose

@e2e exclude Backend MCP tool surface; invoked by the AI orchestrator (Hermiq) over JSON-RPC and the chat facade, not via the browser UI.

Extend the declarative surface `dossiq-mcp-adoption` established (22 derived read-only tools, REQ-MCP-101 … REQ-MCP-105 — unmodified here) with curated `#[McpTool]` tools on Dossiq's owning services: aggregation reads, one redacted projection, and guard-enforcing writes annotated `scope`×`reach`, default-deny per agent, approval-gated where high-impact — so every automatable Dossiq action is commandable from Hermiq chat under granular per-agent grants.

## ADDED Requirements

### Requirement: REQ-MCP-201 — First IMcpScannableServices opt-in, exact enumeration

Dossiq MUST add `lib/Mcp/DossiqScannableServices.php` implementing `OCA\OpenRegister\Mcp\IMcpScannableServices`, registered in `lib/AppInfo/Application.php`, whose `getScannableServiceClasses()` returns exactly the service classes that carry at least one `#[McpTool]` method — no more (an over-broad list makes unrelated public methods reflection candidates) and no fewer. Dossiq MUST NOT reintroduce any `IMcpToolProvider` implementation (that is the seam `dossiq-mcp-adoption` closed: a hand-written provider shadows derived twins).

#### Scenario: Scanner discovers exactly the curated tools

- **WHEN** `tools/list` is enumerated for `appId = dossiq` after this change
- **THEN** it SHALL contain the 22 derived tools from `dossiq-mcp-adoption` plus exactly the curated ids of REQ-MCP-202 and REQ-MCP-204
- **AND** no `IMcpToolProvider` implementation SHALL exist under `lib/`

### Requirement: REQ-MCP-202 — Curated aggregation reads

Dossiq MUST expose, via `#[McpTool]` on the named owning service, the read tools `dossiq.getDeadlineDashboard` (`DeadlineReportingService`), `dossiq.getDoorlooptijdMetrics` (`DoorlooptijdService`), `dossiq.getKpiOverview` (`KpiAggregationService`), `dossiq.getWorkload` (`WorkQueueService`), and `dossiq.listAvailableTransitions` (`StatusTransitionService`). Each MUST declare `scope: "read"`, `reach: "user"`, `readOnlyHint: true`, and MUST return aggregates or transition metadata — never citizen rows. Reads and writes MUST be separate tools: no curated tool both reports and mutates.

#### Scenario: Deadline-breach question is answerable

- **WHEN** an agent is asked which cases breach their deadline this week
- **THEN** it SHALL be able to answer from `dossiq.getDeadlineDashboard` (optionally joined with the derived `dossiq.termijnInstance.search`) without any write grant and without sweeping case rows

#### Scenario: Reads run under the caller's own RBAC

- **WHEN** a non-privileged user's agent invokes any REQ-MCP-202 tool
- **THEN** the result SHALL reflect only data that user can already read through the Dossiq UI (ambient session, no impersonation)

### Requirement: REQ-MCP-203 — Overdue complaints via redacted projection

Dossiq MUST expose `dossiq.listOverdueComplaints` via `#[McpTool]` on `ComplaintService` (`scope: "read"`, `reach: "user"`, `readOnlyHint: true`), returning per complaint at most: complaint number, subject, category, status, handler, `acknowledgementOfReceiptDeadline`, `afhandelDeadline`, and overdue flags. The projection MUST be applied in the method body and MUST NOT include `complainant` (an embedded citizen record) or any of its fields. The `complaint` schema's dialect posture from REQ-MCP-104 (get-only, no `search`) MUST remain unchanged.

#### Scenario: Overdue complaints without complainant exposure

- **WHEN** an agent asks which complaints are past their afhandeltermijn
- **THEN** `dossiq.listOverdueComplaints` SHALL return the overdue complaints' handling fields
- **AND** no result item SHALL contain `complainant` or any complainant contact detail
- **AND** `dossiq.complaint.search` SHALL still not exist

### Requirement: REQ-MCP-204 — Guard-enforcing write tools, one per action, on the owning service

Dossiq MUST expose exactly these write tools via `#[McpTool]`, each on the named owning service so that every guard, clock, mandate check and notification of the human path also runs on the agent path, and each declaring the listed `scope` and `reach` (Hermiq treats these declarations as the enforcement floor):

- `dossiq.transitionCase` — `StatusTransitionService` (guard evaluation, `statusRecord` emission, automatic actions, termijn recalculation); `scope: "update"`, `reach: "instance"`.
- `dossiq.reassignCase` — `CaseReassignmentService`; `scope: "update"`, `reach: "instance"`.
- `dossiq.completeTask` — workflow engine step completion (`WorkflowEngineService`), never a raw `task` object write; `scope: "update"`, `reach: "instance"`.
- `dossiq.extendDeadline` — `DeadlineExtensionService`; `scope: "update"`, `reach: "instance"`.
- `dossiq.pauseDeadline` / `dossiq.resumeDeadline` — `DeadlinePauseService`; `scope: "update"`, `reach: "instance"`.
- `dossiq.scheduleAppointment` — `AppointmentService`; `scope: "create"`, `reach: "external"` (notifies the citizen).
- `dossiq.cancelAppointment` — `AppointmentService`; `scope: "update"`, `reach: "external"`.
- `dossiq.draftBeschikking` — `BeschikkingGenerationService`, draft only; `scope: "create"`, `reach: "user"`.

No curated write MUST bypass its owning service to write through `ObjectService`, and no derived (`x-openregister-mcp`) write verb MUST be declared on any Dossiq schema (REQ-MCP-102 stands).

#### Scenario: Agent transition runs the full state machine

- **WHEN** an agent invokes `dossiq.transitionCase` for a permitted transition
- **THEN** guard evaluation, `statusRecord` emission, automatic actions and termijn recalculation SHALL all occur exactly as for the same transition performed in the UI

#### Scenario: Guards can say no

- **WHEN** a non-privileged user's agent invokes any REQ-MCP-204 tool on a case that user may not modify
- **THEN** the owning service SHALL deny the action and the tool SHALL return an error, not a partial effect

#### Scenario: Task completion goes through the engine

- **WHEN** an agent completes a task via `dossiq.completeTask`
- **THEN** the workflow engine SHALL advance the step and materialise `isTerminalStatus`
- **AND** no tool SHALL exist that creates or edits a `task` object directly

### Requirement: REQ-MCP-205 — Explicit reach on every curated tool; writes default-deny

Every curated (two-segment) Dossiq tool MUST explicitly declare a `reach` from the closed vocabulary `self`/`user`/`instance`/`external` — a two-segment id carries no verb for Hermiq's `ToolReachResolver` to infer from, and an undeclared reach resolves to `external` (fail-closed), silently over-gating the tool. Every write tool MUST be default-deny: invocable by an agent only after an operator grants that agent that tool (or its scope×reach class) in Hermiq — Dossiq MUST NOT mark any write tool as default-granted.

#### Scenario: Ungranted write is not invocable

- **WHEN** a freshly created agent with no write grants attempts `dossiq.reassignCase`
- **THEN** the invocation SHALL be denied by grant resolution before the Dossiq service is reached

#### Scenario: Declared reach matches the contract

- **WHEN** the tool catalogue is enumerated
- **THEN** every curated Dossiq tool SHALL carry an explicit valid `reach`, and `dossiq.scheduleAppointment` / `dossiq.cancelAppointment` SHALL resolve to `external`

### Requirement: REQ-MCP-206 — Human approval gates on high-impact writes

The following tools MUST be declared approval-gated, such that an invocation produces a pending approval (Hermiq `ApprovalService`, EU AI Act Art. 14 human oversight) and the side effect occurs only after a human approves: `dossiq.reassignCase`, `dossiq.extendDeadline`, `dossiq.pauseDeadline`, `dossiq.resumeDeadline`, `dossiq.scheduleAppointment`, `dossiq.cancelAppointment`, `dossiq.draftBeschikking`, and `dossiq.transitionCase` when the target `statusType.isFinal` is true. `dossiq.transitionCase` to a non-final status and `dossiq.completeTask` MAY execute ungated. Operators MAY tighten (gate more), never loosen below this declaration.

#### Scenario: Reassignment waits for a human

- **WHEN** an agent is told "Henk is out sick — move his open bezwaar cases to Fatima" and invokes `dossiq.case.search(filters: { assignee: "henk", isFinalStatus: false })` followed by `dossiq.reassignCase` per case
- **THEN** each reassignment SHALL pend as an approval and no case SHALL move until the user approves it
- **AND** approved reassignments SHALL execute through `CaseReassignmentService` with its normal notifications

#### Scenario: Closing a case is gated, routine flow is not

- **WHEN** an agent invokes `dossiq.transitionCase` toward a status whose `statusType.isFinal` is true
- **THEN** the invocation SHALL pend for approval
- **AND** the same tool toward a non-final status SHALL execute directly (subject to grants and guards)

### Requirement: REQ-MCP-207 — Standing refusals

Dossiq MUST NOT expose, curated or derived: beschikking approval, signing, or dispatch (`akkoord`/`onderteken`/`verzend` — mandate via `MandaatCheckService`, parafering route, citizen dispatch; drafting per REQ-MCP-204 is the automation boundary); case creation or deletion (intake paths and the Archiefwet own them — REQ-MCP-102's reasoning stands); freeform or bulk status transitions (`StatusTransitionController::freeform`/`bulkExecute` — guard-bypassing and mass-effect variants); and any tool over tenant, mandate, parafering, or audit administration schemas/services.

#### Scenario: An agent cannot issue a beschikking

- **WHEN** an agent is asked to sign and send the beschikking for a case
- **THEN** no tool SHALL exist for approval, signing, or dispatch — only `dossiq.draftBeschikking` (gated, draft only) — and the agent SHALL answer that signing and sending are human acts on the beschikking detail page

#### Scenario: No mass-effect surface

- **WHEN** the tool catalogue is enumerated
- **THEN** no curated tool SHALL accept a list of case ids for a single transition or reassignment invocation

### Requirement: REQ-MCP-208 — Every curated invocation is audited

Every curated tool invocation — including denied grants, pending approvals, approvals, rejections, and executed effects — MUST be traceable in the audit surfaces: the OpenRegister audit trail for object effects and Hermiq's run history / approval records for the invocation chain. Dossiq MUST NOT suppress or bypass audit emission on any curated path. (Known fleet limitation, inherited not widened: object-level audit attributes to the session user, not the agent principal — openregister #369.)

#### Scenario: A gated write leaves a full trail

- **WHEN** a `dossiq.extendDeadline` invocation is approved and executed
- **THEN** the approval record, the invocation, and the resulting termijn change SHALL each be retrievable from their respective audit surfaces after the fact
