# case-management Delta: a-handling-team-opens-its-unclaimed-cases

**Status**: draft
**Scope**: the lists that show cases, after a gesture changes who holds a case or how it reads.

## ADDED Requirements

### Requirement: A case that changes hands leaves every list showing it at once (REQ-CM-CLAIM-02)

After a claim, a reassignment, a bulk transition, a read-state change, a follow change or an attention change succeeds, dossiq SHALL announce it as `cn:objects-changed` for register `dossiq` and schema `case` and on the `cn:page:refresh` bus channel, so the index pages and the dashboard lists on screen read their cases again. A refused gesture SHALL announce nothing.

#### Scenario: A claimed case leaves the queue
@e2e exclude mutates a shared instance: claiming rewrites demo data other suites read; covered by caseClaimAction.spec.js
- **GIVEN** an unclaimed case on the Queue page
- **WHEN** a handler claims it from its row
- **THEN** the Queue reads its cases again and no longer shows it
