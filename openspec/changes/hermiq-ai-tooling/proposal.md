# Hermiq AI tooling for Procest — curated tools over every automatable action

## Why

The product line: **every app provides MCP tooling for all of its actions, so any action can in principle be automated by an AI agent — and users grant rights per agent, very granularly.** Even without automation, chat is a command surface: a caseworker who can say "extend the deadline on case 2026-0412 by two weeks, verdaging under Awb" and approve the resulting action is commanding Procest through Hermiq.

`procest-mcp-adoption` (read it first; this change extends it) delivers half of that: 22 derived read-only tools over 13 curated schemas, the hand-written `ProcestToolProvider` (`procest.listProcesses`, `procest.getProcessDetails`, `ProcestCaseAuthorizer`) deleted. Its design deliberately stopped short and left three seams open as questions: **Q2** (expose `StatusTransitionService::transition` as a guard-enforcing curated write), **Q3** (aggregation reads over `KpiAggregationService` / `DoorlooptijdService` / deadline reporting, introducing the first `IMcpScannableServices` opt-in), **Q4** ("which complaints are overdue" is unanswerable because `complaint` is get-only). Its D6 established the rule this change builds on: *every lawful Procest write goes through a service that enforces a guard, a mandate, a clock or a retention rule* — so the only sound write tool is a curated `#[McpTool]` on the **owning service**, never a derived `ObjectService` write.

The safety model is Hermiq's, not invented here: every tool descriptor carries a CRUD `scope` (`read`/`create`/`update`/`delete`) and an orthogonal `reach` (`self`/`user`/`instance`/`external`, `ToolReachResolver`) measuring blast radius of effect and disclosure; a two-segment curated id (`procest.transitionCase`) carries no verb to infer from, so an undeclared reach **fails closed to `external`**; write scopes are default-deny per agent until granted; high-impact invocations pend on `ApprovalService` (EU AI Act Art. 14 human oversight) and everything lands in the audit trail.

## What Changes

- **First `IMcpScannableServices` opt-in.** `lib/Mcp/ProcestScannableServices.php` (the class `procest-mcp-adoption` D3 explicitly deferred to "the first change that introduces a curated tool") enumerating exactly the services that carry `#[McpTool]` methods; registered in `lib/AppInfo/Application.php`.
- **Curated aggregation reads (resolves Q3, Q4):** `procest.getDeadlineDashboard` (`DeadlineReportingService::dashboard` — "which cases breach their deadline this week"), `procest.getDoorlooptijdMetrics` (`DoorlooptijdService`), `procest.getKpiOverview` (`KpiAggregationService`), `procest.getWorkload` (`WorkQueueService::workload`), `procest.listAvailableTransitions` (`StatusTransitionService`), and `procest.listOverdueComplaints` — a **redacted projection** (no `complainant`) that answers Q4 without reopening `complaint.search`.
- **Curated guard-enforcing writes (resolves Q2), one tool per real user action**, each on its owning service, each annotated `scope`×`reach`, all default-deny: `procest.transitionCase`, `procest.reassignCase`, `procest.completeTask`, `procest.extendDeadline`, `procest.pauseDeadline`, `procest.resumeDeadline`, `procest.scheduleAppointment`, `procest.cancelAppointment`, `procest.draftBeschikking`. Full table with per-tool scope, reach and approval posture in `design.md` §D2.
- **Human approval gates** on every high-impact write — reassignment, every statutory-clock change, beschikking drafting, everything with `reach: external` — via Hermiq's `ApprovalService`; the tool result returns the pending-approval envelope, never a completed side effect.
- **Refusals extended, not relaxed:** `beschikking` akkoord/onderteken/verzend (mandate + parafering + external dispatch), case create/delete, raw status or task writes, and every derived write verb remain non-tools. The read-only posture of the *derived* surface from `procest-mcp-adoption` is unchanged.

## Capabilities

### New Capabilities

_None._ The MCP surface is the existing `mcp-integration` capability; this change widens it with curated tools.

### Modified Capabilities

- `mcp-integration`: extended with REQ-MCP-201 … REQ-MCP-208 (scannable-services opt-in, aggregation reads, redacted complaint projection, guard-enforcing writes with scope×reach, default-deny + fail-closed reach, approval gates, standing refusals, audit posture). REQ-MCP-101 … REQ-MCP-105 from `procest-mcp-adoption` stand unmodified.

## Impact

- **Depends on:** `procest-mcp-adoption` fully applied (provider deleted, dialect live) — this change assumes the derived read surface exists and adds no derived verbs.
- **PHP:** new `lib/Mcp/ProcestScannableServices.php`; `#[McpTool]` attributes (+ explicit `reach`, `scope`, hints) on methods of `lib/Service/DeadlineReportingService.php`, `DoorlooptijdService.php`, `KpiAggregationService.php`, `WorkQueueService.php`, `StatusTransitionService.php`, `CaseReassignmentService.php`, `DeadlineExtensionService.php`, `DeadlinePauseService.php`, `AppointmentService.php`, `BeschikkingGenerationService.php`, `ComplaintService.php` (redacted projection), Workflow engine task completion; registration line in `lib/AppInfo/Application.php`. New thin wrapper methods only where an existing method's signature is not scanner-friendly — no business logic moves.
- **Consumers:** Hermiq resolves the new tools from the registry at turn time; grants ship default-deny, so nothing is invocable until an operator grants it per agent. No Hermiq code change required; hermiq-side grant/approval behaviour is hermiq's own spec, referenced not restated.
- **AVG:** aggregation tools return counts and aggregates, not citizen rows; `procest.listOverdueComplaints` is the only new row-returning read and is field-projected to exclude `complainant`. Writes disclose nothing new; every invocation is audited.
- **Out of scope:** any derived (`x-openregister-mcp`) write verb; beschikking approval/signing/dispatch tools; case creation/destruction tools; hermiq-side grant UI or approval flow changes; the email→case matching job (`email-case-matching`, concurrent) and the leaf surfaces (`leaf-integrations`).
