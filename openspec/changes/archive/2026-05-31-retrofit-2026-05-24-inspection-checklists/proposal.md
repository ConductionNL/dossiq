# Retrofit — inspection-checklists

Describes observed behavior of 3 PHP files (~18 methods) as 3 new REQs covering the inspection-execution surface (controller + inspection service + checklist progress service).

## Affected code units
- lib/Controller/InspectionController.php (7 methods) — inspection lifecycle endpoints
- lib/Service/InspectionService.php (6 methods) — location capture, photo add, completion
- lib/Service/ChecklistService.php (5 methods) — item completion + progress + conformity summary (top-level — duplicate of lib/Service/Inspection/ChecklistService.php which is annotated under Bucket 1; this top-level service appears to focus on per-item completion progress while the Inspection/-namespaced one focuses on template lifecycle. Both are kept for now; consolidation deferred.)

## Approach
- File-level survey
- 3 REQs: controller HTTP surface, inspection service, checklist progress service

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
