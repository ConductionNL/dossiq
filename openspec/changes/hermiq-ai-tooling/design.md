## Context

`dossiq-mcp-adoption` ships the derived read surface (22 tools, 13 schemas, zero write verbs) and deletes `lib/Mcp/DossiqToolProvider.php`. Its design leaves the curated seam explicitly open: Q2 (guard-enforcing status transition), Q3 (aggregation reads + the first `IMcpScannableServices`), Q4 (overdue complaints under the get-only AVG posture). This change is that follow-up.

Mechanics this change builds on, verified in the checkouts:

- **`#[McpTool]`** (`openregister/lib/Mcp/Attribute/McpTool.php`): attribute on a public service method; `AttributeToolScanner` discovers it on the classes an app's `IMcpScannableServices` implementation enumerates (`getScannableServiceClasses(): array` — `openregister/lib/Mcp/IMcpScannableServices.php`), registers id `{appId}.{toolName}`, infers input/output schema from the signature + docblock, forwards only author-declared `scope`/hints. The method runs in-process in the caller's ambient session and owns its own authorization (ADR-041/ADR-063).
- **Reach** (`hermiq/lib/Service/Engine/ToolReachResolver.php`): descriptor key `reach`, closed vocabulary `self` < `user` < `instance` < `external`, measuring blast radius of effect and disclosure — orthogonal to CRUD scope (`sendMail` is `create` yet `external`). A three-segment derived id infers reach from its verb; **a two-segment curated id cannot, so an undeclared reach resolves to `external` — fail closed.** Every curated Dossiq tool therefore declares `reach` explicitly.
- **Grants and approvals** (Hermiq): write scopes are default-deny per agent until an operator grants them (granular per tool per agent); gated invocations pend as `approval` objects via `ApprovalService` (EU AI Act Art. 14 write-path) and resolve asynchronously; run history + the OpenRegister audit trail record every call. These are Hermiq's own specs (`agent-capability-reach`, `human-approval-gate-enforcement`) — referenced as the enforcement point, not restated.

## Goals / Non-Goals

**Goals:**
- Every Dossiq action a caseworker performs that is *safe to automate under a guard-enforcing service* gets exactly one curated tool, reads strictly separated from writes.
- Every write annotated `scope`×`reach`, default-deny, approval-gated where high-impact; the acting agent can never do more than the acting user could.
- The domain's top chat questions become answerable: deadlines about to breach, doorlooptijden, workload, overdue complaints.

**Non-Goals:**
- No derived write verbs — the `x-openregister-mcp` posture from `dossiq-mcp-adoption` (read-only) is untouched.
- No tool for actions whose refusal D6 grounded in law or mandate (see §D3).
- No hermiq-side changes; no bespoke Dossiq approval/consent UI (Hermiq owns oversight).

## Decisions

### D1 — Curated reads (aggregation + one redacted projection)

All `scope: read`, `reach: user` (reads disclose nothing beyond what the caller's own RBAC already reaches — the `ToolReachResolver` doctrine), `readOnlyHint: true`.

| Tool id | Owning service / method | Answers |
|---|---|---|
| `dossiq.getDeadlineDashboard` | `DeadlineReportingService::dashboard` (cf. `DeadlineReportingController::dashboard`) | "Which cases breach their deadline this week?" — buckets by urgency, statutory vs. internal termijnen. |
| `dossiq.getDoorlooptijdMetrics` | `DoorlooptijdService` (cf. `DoorlooptijdController::metrics`) | "How are our doorlooptijden trending per caseType?" |
| `dossiq.getKpiOverview` | `KpiAggregationService` (cf. `KpiController::index`) | "How is the team doing this quarter?" |
| `dossiq.getWorkload` | `WorkQueueService::workload` (cf. `WorkQueueController::workload`) | "Who has capacity for a new bezwaar?" |
| `dossiq.listAvailableTransitions` | `StatusTransitionService` (cf. `StatusTransitionController::available`) | "What can happen next on case X?" — also the read half the write tool below needs. |
| `dossiq.listOverdueComplaints` | `ComplaintService` (new projection method) | Q4. Returns complaint number, subject, category, deadlines, status, handler — **never `complainant`**. Resolves "which complaints are overdue" without reopening `complaint.search` (the AVG reason `complaint` is get-only in REQ-MCP-104). |

Aggregations return aggregates, not rows; `listOverdueComplaints` is the one row-returning read and is projected in the method body (the scanner exposes what the method returns — the redaction lives server-side, not in a hint).

### D2 — Curated writes: one tool per action, on the owning service

The D6 rule, operationalised: the tool *is* the service call, so every guard, clock, mandate check and notification the human path runs, the agent path runs. Scope/reach reasoning: everything that changes an OpenRegister object other instance users see is `reach: instance`; everything that notifies a citizen or an external party is `reach: external`.

| Tool id | Owning service | scope | reach | Approval | Notes |
|---|---|---|---|---|---|
| `dossiq.transitionCase` | `StatusTransitionService` (guard evaluation, `statusRecord` emission, automatic actions, termijn recalculation — the exact machinery a derived `case.update` bypasses, D6) | update | instance | **Gated when the target `statusType.isFinal` is true**; ungated otherwise | Q2 resolved. Freeform transitions (`StatusTransitionController::freeform`) are NOT exposed — guard-bypassing by construction. |
| `dossiq.reassignCase` | `CaseReassignmentService` (cf. `reassignExecute`) | update | instance | **Always gated** | Changes another user's worklist — the canonical instance-reach write. |
| `dossiq.completeTask` | Workflow engine task completion (`WorkflowEngineService` step advancement — never a raw `task` write, D6) | update | instance | Ungated | Task *creation* is not exposed: a free-floating agent task desynchronises the engine. |
| `dossiq.extendDeadline` | `DeadlineExtensionService` (cf. `TermijnController::verleng`) | update | instance | **Always gated** | Verdaging moves a statutory clock; `extensionCount` and notifications ride along. |
| `dossiq.pauseDeadline` / `dossiq.resumeDeadline` | `DeadlinePauseService` (cf. `TermijnController::pauze`/`hervat`) | update | instance | **Always gated** | Opschorting — same statutory-clock argument. |
| `dossiq.scheduleAppointment` | `AppointmentService::create` | create | **external** | **Always gated** | Books a slot and notifies the citizen — leaves the building. |
| `dossiq.cancelAppointment` | `AppointmentService` (cf. `AppointmentController::cancel`) | update | **external** | **Always gated** | Citizen-visible cancellation. |
| `dossiq.draftBeschikking` | `BeschikkingGenerationService` | create | user | **Always gated** | Produces a **draft only**, visible to the caseworker; the beschikking lifecycle beyond draft is refused (§D3). |

The approval column is Dossiq's declared posture; Hermiq enforces it (an operator can only tighten, never loosen, from the tool's declaration). "Gated" means the invocation returns Hermiq's pending-approval envelope and the side effect happens only after a human approves — asynchronously, attributable, audited.

### D3 — Standing refusals (D6 extended)

| Never a tool | Why |
|---|---|
| `beschikking` akkoord / onderteken / verzend (`BeschikkingController::akkoord/onderteken/verzend`) | Approval requires mandate (`MandaatCheckService`), signing runs the parafering route (`ParafeerRouteService` / `ParaferingApprovalBridge`), dispatch reaches the citizen. An agent issuing a beschikking is a mandate breach — D6 said it for `decision.create`; the same holds one layer down. Drafting (D2) is the automation boundary. |
| Case create / delete | Unchanged from D6: intake paths own creation (leaf-integrations' `FormsIntakeService`, DSO, KCC); vernietiging is an Archiefwet act. |
| Freeform/bulk transitions (`freeform`, `bulkExecute`) | The guard-bypassing and mass-effect variants of `transitionCase`. Bulk = one approval per case or nothing. |
| Raw `task` / `case` / `bezwaar` / `complaint` object writes | The derived surface stays read-only; REQ-MCP-102 is not weakened. |
| Tenant / mandate / parafering / audit administration | Control-plane, excluded wholesale in `dossiq-mcp-adoption` D2; tooling them would be reconnaissance-plus-write. |

### D4 — Chat is a command surface (scenarios the tool set must serve)

1. *"Which cases breach their deadline this week?"* → `dossiq.getDeadlineDashboard` (optionally `dossiq.termijnInstance.search` from the derived surface for the per-case list). Read-only, no grant beyond read needed.
2. *"Henk is out sick — move his open bezwaar cases to Fatima."* → `dossiq.case.search(filters: {assignee: henk, isFinalStatus: false})` (derived) + one `dossiq.reassignCase` per case, each pending approval; the user approves the batch in Hermiq's oversight surface. Nothing moved until approved.
3. *"Draft the toewijzingsbeschikking for case 2026-0412 and put it on my desk."* → `dossiq.case.get` + `dossiq.draftBeschikking` (gated); signing and sending remain human acts on the beschikking detail page.

### D5 — Scanner hygiene

`DossiqScannableServices::getScannableServiceClasses()` lists **exactly** the classes carrying `#[McpTool]` — an over-broad list makes every future public method a reflection candidate. Where an existing method signature is not scanner-friendly (array blobs, controller-shaped params), a thin typed wrapper method on the same service carries the attribute and delegates; no logic moves. Tool names are the wrapper/method names — English, verb-first, matching the ids above.

## Risks / Trade-offs

- **[A curated write with a weaker in-method guard than its controller]** → The attribute goes on service methods whose controllers already delegate authorization to the service, and the verification phase invokes each write as a non-privileged user expecting denial (the guard must be shown able to say NO).
- **[Reach misdeclaration]** → Fail-closed helps (undeclared → `external` → maximally gated); the residual risk is *over*-declaring reach and silently over-gating — verification asserts each tool's resolved reach equals the D2 table.
- **[Tool-count context burn]** → +15 curated tools on top of 22 derived ≈ 37. Still ~5% of the naive surface; the aggregation reads *replace* row-sweeps, reducing tokens per answer. If selection accuracy degrades, `getKpiOverview` and `getWorkload` are the first to drop.
- **[Approval fatigue]** → Everything gated is genuinely consequential (statutory clocks, citizen contact, worklists). `transitionCase` on non-final statuses and `completeTask` are deliberately ungated so routine flow doesn't train users to rubber-stamp.
- **[Audit attributes to the session user, not the agent principal]** → Known fleet gap (openregister #369), inherited from `dossiq-mcp-adoption`, not widened here; Hermiq's run history carries the agent identity in the meantime.

## Migration Plan

1. Land `DossiqScannableServices` + read-tool attributes; verify via `tools/list` (22 derived + 6 reads).
2. Land write-tool attributes with their reach/scope/approval declarations; verify default-deny (fresh agent: writes invisible/denied), then grant-and-approve one `reassignCase` end to end on a dev instance.
3. **Rollback:** remove the attribute(s) or the registration line — the catalogue shrinks accordingly; no data shape changed.

## Open Questions

- **Q1** — Should `dossiq.transitionCase` gating key off more than `statusType.isFinal` (e.g. transitions that trigger automatic external actions)? Needs a survey of `automaticAction` configs in the wild.
- **Q2** — Batch reassignment as a first-class tool (one approval for N cases) vs. N gated calls: N calls is safer but noisy for the 40-case sick-leave scenario. Product call.
- **Q3** — Should `dossiq.listOverdueComplaints`'s projection become an OpenRegister-level capability (field denylist on the dialect, `dossiq-mcp-adoption` Q1) so `complaint.search` could return redacted rows instead? If that lands, this tool dissolves into the derived surface.
