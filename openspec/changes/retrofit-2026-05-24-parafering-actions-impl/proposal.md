# Retrofit — parafering-actions (general-controller surface)

Describes observed behavior of 3 PHP files (~23 methods) — the general `ParaferingController`, `ParaferingService`, and `ParaferingNotificationService` — as 3 new REQs extending the parafering-actions capability. The spec is marked retired with `canonical_home: case-management/spec.md`; the REQs here narrowly document the general-controller surface (not the per-action `ParafeerActieController` surface which is already annotated).

## Affected code units
- lib/Controller/ParaferingController.php (9 methods) — voorstel CRUD + start + per-action endpoints + audit trail
- lib/Service/ParaferingService.php (10 methods) — voorstel lifecycle + action execution + step resolution
- lib/Service/ParaferingNotificationService.php (4 methods) — step-activated / returned / reminder / completed

Note: the retired status reflects that parafering is folded into case-management at the capability level. The implementation files still need annotation; this delta captures their per-method REQs on the original spec rather than re-opening the canonical case-management spec.

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
