# Roles & Decisions Implementation

> PARTIALLY REVERTED 2026-06-01: stays archived, but TASK-RD-03 was over-claimed — `CaseDetail.vue` does not exist, so `DecisionsSection.vue` (which exists) was never integrated and remains orphaned (never imported). TASK-RD-03 is un-checked; DecisionsSection.vue, decisionHelpers.js, and ResultSection.vue remain real. The roles-decisions main spec already honestly states "Decisions: Not implemented" / "No Decisions section on the case detail page", so no main-spec edit was needed.

## Summary
Implement the missing decisions section for case detail view and enhance the result section with archival metadata display. The roles (participants) section is already substantially implemented.

## Scope
- REQ-DECISION-001: Decision CRUD UI on case detail
- REQ-DECISION-002: Decision validity period display
- REQ-DECISION-005: Decisions section on case detail
- REQ-RESULT-001: Enhance result display with archival metadata
- REQ-ROLE-006: Role validation (field requirements)
