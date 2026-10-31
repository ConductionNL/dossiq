## MODIFIED Requirements

### Requirement: Coordinator Workload Endpoint [MVP]

The system MUST expose `GET /api/work-queue/workload`, returning per-handler
open-case counts for the members of the reader's teams, a member without
open cases listed with 0 (every handler for a reader in no team), gated to the coordinator role
(Nextcloud admin group membership, mirroring the existing coordinator model in
`SubstitutionController`). Non-coordinators MUST receive 403; unauthenticated
callers MUST receive 401.

@e2e exclude Requires an admin-group test user and pre-seeded multi-handler
case data; not assertable without seeded fixtures.

#### Scenario: Coordinator sees per-handler counts
- GIVEN an admin-group user, and open cases distributed across 3 handlers
- WHEN the admin calls `GET /api/work-queue/workload`
- THEN the response MUST list each handler with their open-case count

#### Scenario: Only the people of the reader's teams are listed
- GIVEN a coordinator in team A, handlers Anouk (in team A) and Beheerder (not in team A), each with open cases
- WHEN the coordinator calls `GET /api/work-queue/workload`
- THEN the response MUST list Anouk with all her open cases
- AND it MUST NOT list Beheerder
- AND a team-A member without open cases MUST be listed with 0

#### Scenario: Non-coordinator is denied
- GIVEN an authenticated non-admin user
- WHEN they call `GET /api/work-queue/workload`
- THEN the system MUST respond 403
