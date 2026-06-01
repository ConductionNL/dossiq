# Case Management Implementation

> PARTIALLY REVERTED 2026-06-01: stays archived, but TASK-CM-01/02/05 were over-claimed — `CaseList.vue`/`CaseDetail.vue` do not exist (app is a CnAppRoot manifest, no SPA list/detail view files). Those tasks are un-checked; the real panels (CustomPropertiesPanel, DocumentChecklist) and caseValidation.js remain done. The case-management main spec's Implementation Status was corrected to drop the false CaseList/CaseDetail claims.

## Summary
Implement the remaining gaps in the case-management spec, focusing on MVP features that are partially or not yet implemented: case list filters (priority, handler, overdue), case search, custom properties panel stub, required documents checklist stub, and improved case validation with better error messages.

## Scope
- REQ-CM-04d/e/f: Add filter by handler, priority, and overdue status in case list
- REQ-CM-09: Custom properties panel (stub with property display and edit)
- REQ-CM-10: Required documents checklist (stub showing document completion status)
- REQ-CM-20: Strengthen case validation (case type validity window errors)
- REQ-CM-23: Case search (search by title, description, identifier)

## Out of Scope
- REQ-CM-17: Case suspension (V1)
- REQ-CM-18: Sub-cases (V1)
- REQ-CM-19: Confidentiality enforcement (V1)
- REQ-CM-14c/d: Status blocking by properties/documents (V1)

## Approach
- Add filter controls to CaseList.vue using existing CnIndexPage capabilities
- Create CustomPropertiesPanel.vue and DocumentChecklist.vue components
- Enhance caseValidation.js with more specific error messages
- Add search functionality to case list
