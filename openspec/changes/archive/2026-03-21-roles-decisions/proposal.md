# Roles & Decisions Specification

## Problem
Roles define the relationship between participants (Nextcloud users or external contacts) and cases -- who is involved and in what capacity. Results record the formal outcome of a completed case, linking to a predefined result type that controls archival rules. Decisions are formal administrative choices made on cases, with legal validity periods and publication requirements.
Together, these three entities govern participation, outcomes, and formal decision-making within the case lifecycle.
**Standards**: Schema.org (`Role`, `ChooseAction`), CMMN (case outcomes, case participants), ZGW (`Rol`, `Resultaat`, `Besluit`, `RolType`, `ResultaatType`, `BesluitType`)
**Primary feature tier**: MVP (roles, results), V1 (decisions, role types, result types, decision types)
**Competitive context**: Dimpact ZAC provides OPA-based policy authorization with 5 policy domains and 51+ individual permissions, plus formal decision (besluit) recording with publication dates and withdrawal. xxllnc Zaken implements 4-level case authorization (search/read/write/manage) and threaded messaging linked to cases. ArkCase uses participant-based row-level ACL with functional access control mapped from LDAP groups. Flowable provides identity links connecting users/groups to tasks and cases with delegation support. Procest takes a simpler role-based approach that maps to ZGW `Rol` with generic role categories, suitable for the Dutch government context.
---

## Proposed Solution
Implement Roles & Decisions Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the roles-decisions specification.

## Success Criteria
- Assign a handler to a case
- Assign multiple participants with different roles
- Assign the same participant with multiple roles
- Reassign a handler
- Remove a role from a case
