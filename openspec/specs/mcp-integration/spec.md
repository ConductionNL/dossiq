---
status: done
retrofit: true
---

# MCP Integration Specification

## Purpose

@e2e exclude Backend MCP tool provider; invoked by AI orchestrator, not via browser UI.

Provide the procest-side `IMcpToolProvider` implementation that the openregister AI orchestrator (per ADR-034 / ADR-035) discovers and invokes during an AI Chat Companion turn. The MVP exposes two read-only tools (`procest.listProcesses`, `procest.getProcessDetails`) with bounded result sets, per-object authorisation enforced inside `invokeTool`, and structured error envelopes — so that an LLM can ask "what cases am I working on?" and "what's the current step on case X?" without being able to mutate anything or read cases the caller isn't entitled to see.

The full per-app MCP tool set (startProcess, advanceStep, listMyTasks, getTaskDetails — tracked in procest#416) is intentionally out of scope here.

## Requirements

### REQ-001: Implement IMcpToolProvider with stable app id and hardcoded tool catalogue

The system SHALL implement `OCA\OpenRegister\Mcp\IMcpToolProvider` with `getAppId()` returning the procest app id and `getTools()` returning a hardcoded catalogue of exactly two read-only tools — `procest.listProcesses` and `procest.getProcessDetails` — with their `id`, `name`, `description`, and `inputSchema` (JSON Schema shape) so the orchestrator can advertise them to the LLM verbatim.

#### Scenario: getAppId stability

- WHEN the orchestrator queries the provider's app id
- THEN `getAppId()` SHALL return the procest application id (`OCA\Procest\AppInfo\Application::APP_ID`)

#### Scenario: getTools returns 2 descriptors

- WHEN the orchestrator queries `getTools()`
- THEN the result SHALL be exactly the two-tool MVP catalogue with stable `id` strings prefixed `procest.`

#### Notes

- The catalogue lives in a `private const TOOL_DESCRIPTORS` so unit tests can assert it as a fixture.

### REQ-002: listProcesses tool with bounded limit and optional status filter

The system SHALL implement `procest.listProcesses(limit?, status?)` returning up to `LIMIT_MAX=50` (default `20`) running process instances the caller is entitled to read, optionally filtered to a single status type id, formatted as MCP source descriptors.

#### Scenario: Limit parsing

- WHEN `limit` is supplied
- THEN the helper SHALL clamp it to `[1, LIMIT_MAX]`, defaulting to `20` when absent or out-of-range

#### Scenario: Status filter

- WHEN `status` is supplied
- THEN the case list SHALL be restricted to cases whose current `statusType` matches that id

#### Scenario: Result cap

- WHEN the filtered list exceeds `ITEMS_CAP=20`
- THEN the result SHALL be truncated to `ITEMS_CAP` items before returning to the orchestrator (independent of the per-tool `limit` argument)

### REQ-003: getProcessDetails tool returning case + history

The system SHALL implement `procest.getProcessDetails(caseId)` returning a single case with its current step plus the case's history, packaged as MCP source descriptors that the LLM can quote in its response.

#### Scenario: caseId argument parsing

- WHEN `caseId` is missing or non-UUID-shaped
- THEN the tool SHALL return a structured error envelope (REQ-005) without invoking the case store

#### Scenario: Not found

- WHEN no case matches the resolved id
- THEN the tool SHALL return a structured error envelope with code indicating not-found

#### Scenario: History inclusion

- WHEN the case is resolved and the caller is entitled
- THEN the tool SHALL load the case history (capped at `ITEMS_CAP`) and include both case + history in the source-descriptor payload

### REQ-004: Per-object authorisation (assignee / role / admin) inside invokeTool

The system SHALL enforce per-object authorisation inside `invokeTool` AFTER argument validation but BEFORE business logic, via `canReadCase($case)`. A caller MAY read a case only when one of these holds: the caller is an admin (procest-admin group OR NC admin group), the caller is the case's assignee (primary handler), or a role record exists linking the caller's user id to the case uuid.

#### Scenario: Admin always allowed

- WHEN the current user is in `procest-admin` or is an NC admin
- THEN `canReadCase` SHALL return `true` without further checks

#### Scenario: Assignee read

- WHEN the current user is the case's assignee
- THEN `canReadCase` SHALL return `true`

#### Scenario: Role-mediated read

- WHEN the current user has a role record linking them to the case uuid
- THEN `canReadCase` SHALL return `true`

#### Scenario: Fail-closed contract

- WHEN none of the three checks pass
- THEN `canReadCase` SHALL return `false`
- AND the authorisation helper SHALL NOT be wrapped in `catch(\Throwable)` — exceptions during the resolve MUST propagate (OWASP A01:2021 / ADR-005)

#### Notes

- `isAdmin($userId)` delegates to `IGroupManager`, mirroring the pattern in `StatusTransitionService`.

### REQ-005: Standard error envelopes and result-cap

The system SHALL return all errors as a standard envelope `errorEnvelope(code, message)` rather than throwing — so the orchestrator can pass the error to the LLM as a structured tool result without ad-hoc exception handling.

#### Scenario: Validation error envelope

- WHEN argument validation fails (missing `caseId`, malformed UUID, etc.)
- THEN the helper SHALL return `errorEnvelope(<code>, <message>)`

#### Scenario: Authorisation error envelope

- WHEN `canReadCase` returns `false`
- THEN the helper SHALL return an authorisation-error envelope without leaking case data

#### Scenario: ITEMS_CAP for all source-descriptor lists

- WHEN any list of source descriptors is being built (cases, history, etc.)
- THEN the result SHALL be capped at `ITEMS_CAP = 20` before envelope construction

#### Notes

- The standardised error shape means the orchestrator can match on it without per-tool special-casing.
