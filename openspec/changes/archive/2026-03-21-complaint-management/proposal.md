# complaint-management Specification

## Problem
Implement klachtafhandeling (complaint management) as a first-class entity in Procest with its own lifecycle, escalation to formal cases, disposition tracking, and frequency analysis. Complaints are a distinct intake channel from regular cases: they follow a lighter process, have legal response deadlines (Awb chapter 9), and can escalate to formal cases when the complaint reveals a larger issue.

## Proposed Solution
Implement complaint-management Specification following the detailed specification. Key requirements include:
- Requirement: Complaints MUST be first-class entities with dedicated schema
- Requirement: Complaints MUST follow the Awb chapter 9 lifecycle with enforced deadlines
- Requirement: Complaints MUST support a hearing (hoorgesprek)
- Requirement: Complaints MUST support escalation to formal cases
- Requirement: Disposition tracking MUST record how complaints are resolved

## Scope
This change covers all requirements defined in the complaint-management specification.

## Success Criteria
- Register a new complaint via intake form
- Complaint numbering is sequential per year
- Complaint intake from multiple channels
- Complaint data validation
- Awb deadline calculation on complaint creation
