# consultation-management Specification

## Problem
Implement structured inter-departmental consultation (adviesaanvraag) as a first-class entity in Procest. A consultation is a mini-case linked to a parent case, with its own lifecycle, assigned participants, documents, due dates, and formal response. This replaces informal email-based advice requests with tracked, auditable departmental coordination.

## Proposed Solution
Implement consultation-management Specification following the detailed specification. Key requirements include:
- Requirement: Consultations MUST be first-class entities linked to parent cases
- Requirement: Consultations MUST have their own lifecycle with deadline enforcement
- Requirement: Consultations MUST support structured document exchange
- Requirement: Consultation responses MUST be structured with formal conclusions
- Requirement: Consultation events MUST appear in the parent case timeline

## Scope
This change covers all requirements defined in the consultation-management specification.

## Success Criteria
- Create a consultation for a case
- Multiple consultations per case with independent lifecycles
- Consultation references parent case bidirectionally
- Consultation data validation
- Consultation lifecycle status transitions
