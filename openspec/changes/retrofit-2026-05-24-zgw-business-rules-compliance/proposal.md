# Retrofit — zgw-business-rules-compliance

Describes observed behavior of 5 PHP files (~64 methods) implementing ZGW per-API business-rules validators as 5 new REQs. The existing `zgw-business-rules-compliance` spec is delta-only (changed requirements against `procest-case-management`); this retrofit captures the actual implementation surface that the controllers call before write operations.

## Affected code units
- lib/Service/ZgwRulesBase.php (17 methods) — shared base for rule services (context, cross-register lookups, mapping resolution)
- lib/Service/ZgwBusinessRulesService.php (8 methods) — cross-component validator + facade
- lib/Service/ZgwBrcRulesService.php (10 methods) — BRC besluit rules
- lib/Service/ZgwDrcRulesService.php (13 methods) — DRC document rules
- lib/Service/ZgwZtcRulesService.php (16 methods) — ZTC catalogus/zaaktype rules

## Approach
- File-level survey of rule services
- One REQ per logical validator surface (base + 4 per-API services + cross-cutting facade)
- Notes flag that ZgwZrcRulesService is partially annotated under enforcement-lhs (file-level inherits)

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
