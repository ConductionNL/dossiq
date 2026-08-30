# Retrofit — ai-assistance

Describes observed behavior of 2 PHP files (~35 methods) — AI controller and service — as 5 new REQs covering the human-in-the-loop AI surface for procest. (Distinct from `mcp-integration` which covers the per-app MCP tool provider registered with the OR AI orchestrator.)

## Affected code units

- lib/Controller/AiController.php (13 methods) — classify / extract / ask / summarize / suggestRouting / suggestNext / recordAction / auditIndex / settings (get/update) / healthCheck
- lib/Service/AiService.php (22 methods) — enablement flags, PII stripping, AI feature implementations, audit-trail recording, n8n MCP orchestration

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
