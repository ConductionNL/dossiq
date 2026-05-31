# Retrofit — mcp-integration

Describes observed behavior of 1 PHP file (~21 methods) — procest's MCP tool provider that the openregister AI orchestrator (per ADR-034 / ADR-035) calls when serving an AI Chat Companion turn — as 5 new REQs. Distinct from `ai-assistance` (which covers the human-facing AI features) and from `mcp-tools-fleet` (orchestrator side).

## Affected code units

- lib/Mcp/ProcestToolProvider.php (21 methods) — implements `OCA\OpenRegister\Mcp\IMcpToolProvider`; exposes 2 read-only MVP tools (`procest.listProcesses`, `procest.getProcessDetails`) with per-object authorisation, argument validation, and capped result envelopes

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
