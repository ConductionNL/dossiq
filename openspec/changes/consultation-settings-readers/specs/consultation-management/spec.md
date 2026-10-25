# consultation-management Delta: consultation-settings-readers

## ADDED Requirements

### Requirement: An advice request without a deadline gets the default term

`createConsultation` SHALL set `latestResponseDate` to today plus `consultation_default_deadline_days` (28 when unset or not a positive whole number) when the request carries no deadline. A given deadline SHALL be kept.

#### Scenario: No deadline given
- GIVEN the default term is 21 days and today is 2026-10-24
- WHEN an advice request is created without `latestResponseDate`
- THEN it is stored with `latestResponseDate` 2026-11-14

### Requirement: Advice requests due within the warning offset are listed

`GET /api/consultations/due-soon` SHALL list the open and in-handling advice requests whose deadline is today or within `consultation_warning_offset_days` (5 when unset), with the offset it used.

#### Scenario: Offset 5
- GIVEN today is 2026-10-24 and deadlines 2026-10-26, 2026-10-30 and 2026-10-20
- THEN only the 2026-10-26 request is listed

### Requirement: The external invitation uses the configured response address

When `consultation_external_response_url` is an https address, inviting an external advisory body SHALL answer `responseUrl` = that address (one slash) plus the last path segment of the access link, and a `message` naming the subject, the deadline and that address. Without the setting the access link is the address.

#### Scenario: Loket address
- GIVEN the setting `https://loket.zuiddrecht.nl/advies` and the link `https://cloud.zuiddrecht.nl/l/abc`
- THEN `responseUrl` is `https://loket.zuiddrecht.nl/advies/abc`

### Requirement: Advisory bodies past the bottleneck threshold are flagged

`GET /api/consultations/bottlenecks` SHALL give per advisory body, over requests created in the last 30 days, the total, the late ones (deadline passed while not answered, or answered after it), the late share, and `flagged` when the share is above `consultation_bottleneck_threshold` (0.2 when unset).

#### Scenario: Three of ten late
- GIVEN an advisory body with 10 requests in 30 days, 3 late, threshold 0.2
- THEN it is flagged with share 0.3

### Requirement: A consultation list shows only requests on cases the caller may read

`GET /api/consultations/due-soon` and `GET /api/consultations/overdue` SHALL keep only the advice requests whose parent case the caller may read, by the case pages' own check (`CaseAccessGuard::hasCaseReadAccess`), asked of each request before the list is built. The configured background service account, in its group `dossiq-background-service`, SHALL see every request: it is the account the scheduled consultation workflows call as. A request without a case SHALL never be listed.

#### Scenario: A reader without access
- GIVEN a user who may read neither case of two advice requests
- WHEN they ask for the due-soon list
- THEN the list is empty

### Requirement: The bottleneck report is for coordinators

`GET /api/consultations/bottlenecks` aggregates across every case and SHALL answer only administrators, members of `dossiq-coordinators` and the background service account; any other signed-in user SHALL get 403 before the report is computed, an anonymous caller 401.

#### Scenario: A plain user
- GIVEN a signed-in user outside those groups
- THEN the report answers 403
