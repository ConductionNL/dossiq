# Tasks — hermiq-ai-tooling

**Gate T0 blocks everything below:** `dossiq-mcp-adoption` must be fully applied first (`lib/Mcp/DossiqToolProvider.php` deleted, dialect imported, 22 derived tools live) — this change adds curated tools on top of that surface and must not race the provider surgery.

## 1. Scannable-services opt-in (REQ-MCP-201)

- [x] 1.1 New `lib/Mcp/DossiqScannableServices.php` implementing `OCA\OpenRegister\Mcp\IMcpScannableServices`; `getScannableServiceClasses()` returns exactly the classes attributed in phases 2–3. Register in `lib/AppInfo/Application.php`. SPDX + full PHPDoc.
- [x] 1.2 Assert no `IMcpToolProvider` implementation exists anywhere under `lib/` (grep, non-zero file count).

## 2. Curated reads (REQ-MCP-202, REQ-MCP-203)

- [x] 2.1 `#[McpTool]` (with explicit `scope: read`, `reach: user`, `readOnlyHint: true`, agent-facing descriptions) on `DeadlineReportingService` (`getDeadlineDashboard`), `DoorlooptijdService` (`getDoorlooptijdMetrics`), `KpiAggregationService` (`getKpiOverview`), `WorkQueueService` (`getWorkload`), `StatusTransitionService` (`listAvailableTransitions`). Add thin typed wrapper methods only where an existing signature is not scanner-friendly; move no logic.
- [x] 2.2 `ComplaintService`: new projected method for `dossiq.listOverdueComplaints` — redaction in the method body, `complainant` never in the return shape. PHPUnit: a complaint with a populated `complainant` yields a result item without it (the projection must be shown able to strip).

## 3. Curated writes (REQ-MCP-204, REQ-MCP-205, REQ-MCP-206)

- [ ] 3.1 `#[McpTool]` write tools per the design D2 table: `transitionCase` (`StatusTransitionService`), `reassignCase` (`CaseReassignmentService`), `completeTask` (engine step completion via `WorkflowEngineService` — never a raw `task` write), `extendDeadline` (`DeadlineExtensionService`), `pauseDeadline`/`resumeDeadline` (`DeadlinePauseService`), `scheduleAppointment`/`cancelAppointment` (`AppointmentService`), `draftBeschikking` (`BeschikkingGenerationService`, draft only). Every attribute declares `scope`, explicit `reach`, `readOnlyHint: false`, and the approval posture per REQ-MCP-206.
- [ ] 3.2 Assert every write delegates to its owning service (no `ObjectService` writes on any curated path) and that no derived write verb was added to any schema (`grep -n '"create"\|"update"\|"delete"' inside every x-openregister-mcp block` → zero).
- [ ] 3.3 PHPUnit per write tool: (a) the guard says NO for a non-privileged caller; (b) `transitionCase` emits a `statusRecord` and recalculates termijnen; (c) `completeTask` advances the engine step; (d) `draftBeschikking` produces a draft and never touches akkoord/onderteken/verzend paths.

## 4. Specs, quality, changelog

- [ ] 4.1 Sync the delta into `openspec/specs/mcp-integration/spec.md` at archive time (REQ-MCP-101…105 unmodified, 201…208 appended); ensure no `@spec` tag points at a change path (gate-46). `openspec validate` clean.
- [ ] 4.2 `php -l` on every touched file; `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) clean; PHPUnit zero new failures against a self-measured baseline; run the hydra-gates suite and resolve any finding.
- [ ] 4.3 CHANGELOG entry: curated Hermiq AI tooling — 6 reads + 9 writes, scope×reach annotated, default-deny, approval-gated; derived surface unchanged.

## 5. Verify on a live instance

- [ ] 5.1 `tools/list` for `dossiq`: 22 derived + 15 curated tools, every curated id two-segment and carrying an explicit valid `reach`; `scheduleAppointment`/`cancelAppointment` resolve `external`; no tool id ends in `.create`/`.update`/`.delete` beyond the (still absent) derived writes.
- [ ] 5.2 Default-deny proven: a fresh agent with no write grants is denied `dossiq.reassignCase` before the service runs; the same agent can still call `dossiq.getDeadlineDashboard`.
- [ ] 5.3 End-to-end gated write: grant `reassignCase` to a test agent, run the "reassign Henk's open cases to Fatima" scenario — each call pends in Hermiq approvals, nothing moves pre-approval, approval executes through `CaseReassignmentService` with notifications, and the approval + invocation + object change are each retrievable from their audit surfaces (REQ-MCP-208).
- [ ] 5.4 Chat scenarios from design D4 answered live via the Hermiq chat facade: deadline-breach question (read-only), reassignment (gated), beschikking draft (gated, draft-only) — confirming signing/sending remain unavailable to the agent (REQ-MCP-207).

## Built 2026-10-10 (lane L6, PR stacked on #3558 which applies dossiq-mcp-adoption, so gate T0 holds on this branch)

- 1.1: `lib/Mcp/DossiqScannableServices.php`, registered in `lib/AppInfo/Registrar/AppHostRegistrar.php` under `OCA\OpenRegister\Mcp\IMcpScannableServices::dossiq` (the alias OpenRegister resolves in the app's own container). `tests/Unit/Mcp/DossiqScannableServicesTest.php` holds the list against the attributed classes in lib/ both ways and checks the registration.
- 1.2: `tests/Unit/Mcp/DossiqMcpDialectTest.php::testNoHandWrittenToolProviderIsLeft` (from dossiq-mcp-adoption).
- 2.1: the five reads sit on two thin tool classes, `lib/Service/Mcp/ReportingTools.php` (getDeadlineDashboard, getDoorlooptijdMetrics, getKpiOverview, getWorkload) and `lib/Service/Mcp/CaseTools.php` (listAvailableTransitions), not on the owning services. Reason: the gates live in the controllers (ReportingAudience, the coordinator check, CaseAccessGuard read access, the KPI widget narrowing), and an attributed method on the service would have skipped them. Each tool runs its controller's gate and calls the owning service; no figure is computed in the tool. `DeadlineReportingService::dashboard` does not exist: the tool calls `getTermijnKpi()`, as `DeadlineReportingController::dashboard()` does. Tests: `tests/Unit/Service/Mcp/ReportingToolsTest.php`, `CaseToolsTest.php`.
- 2.2: `ComplaintService::listOverdueComplaints()` rebuilds each row from an allowlist; `ComplaintServiceTest::testListOverdueComplaintsNeverCarriesTheComplainant` (mutation-checked: adding `complainant` to the allowlist fails it).
- OPEN GAP for REQ-MCP-205: OpenRegister's `#[McpTool]` has no `reach` parameter and `AttributeToolScanner` forwards none, so no curated tool can declare one. Every curated id therefore resolves to `external` (fail closed), which is stricter than the design, never looser. Sibling ask filed (for-ruben/dossiq-sibling-asks.md). The approval posture of D2 has no declaration channel either: hermiq gates every ungranted non-read tool, so every write would be gated.
- Next: phase 3 writes. Design drift found on development: reassignment is now OpenRegister's bulk job (`ReassignCasesAction` via `SubstitutionController::releaseCaseload()`), the beschikking draft is `DecisionService::compose()` (`BeschikkingController::create()`), and task completion is `CaseTaskController::complete()` over the engine task. The term tools need a case-mutation check the term controller does not run (it only checks the session).

## Re-verified 2026-09-09

Unstarted. `DossiqScannableServices.php` does not exist, and no `#[McpTool]` attribute appears
anywhere under `lib/` — the four `McpTool` matches there are all substrings of
`IMcpToolProvider` in `lib/Mcp/DossiqToolProvider.php`.

Keep as backlog, strictly behind `dossiq-mcp-adoption`. Task T0 already says so; nothing here is
actionable while the hand-written provider is the one that runs.
