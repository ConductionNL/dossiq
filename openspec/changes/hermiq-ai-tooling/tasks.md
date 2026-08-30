# Tasks — hermiq-ai-tooling

**Gate T0 blocks everything below:** `dossiq-mcp-adoption` must be fully applied first (`lib/Mcp/DossiqToolProvider.php` deleted, dialect imported, 22 derived tools live) — this change adds curated tools on top of that surface and must not race the provider surgery.

## 1. Scannable-services opt-in (REQ-MCP-201)

- [ ] 1.1 New `lib/Mcp/DossiqScannableServices.php` implementing `OCA\OpenRegister\Mcp\IMcpScannableServices`; `getScannableServiceClasses()` returns exactly the classes attributed in phases 2–3. Register in `lib/AppInfo/Application.php`. SPDX + full PHPDoc.
- [ ] 1.2 Assert no `IMcpToolProvider` implementation exists anywhere under `lib/` (grep, non-zero file count).

## 2. Curated reads (REQ-MCP-202, REQ-MCP-203)

- [ ] 2.1 `#[McpTool]` (with explicit `scope: read`, `reach: user`, `readOnlyHint: true`, agent-facing descriptions) on `DeadlineReportingService` (`getDeadlineDashboard`), `DoorlooptijdService` (`getDoorlooptijdMetrics`), `KpiAggregationService` (`getKpiOverview`), `WorkQueueService` (`getWorkload`), `StatusTransitionService` (`listAvailableTransitions`). Add thin typed wrapper methods only where an existing signature is not scanner-friendly; move no logic.
- [ ] 2.2 `ComplaintService`: new projected method for `dossiq.listOverdueComplaints` — redaction in the method body, `complainant` never in the return shape. PHPUnit: a complaint with a populated `complainant` yields a result item without it (the projection must be shown able to strip).

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
