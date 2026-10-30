## ADDED Requirements

### Requirement: A case opened with intake obligations waits on them

When a case is received (created, or sent from draft) with entries in `intakeObligations` whose state is `awaiting`, dossiq SHALL place one obligation per entry on the case, with the entry's kind, the person's name, the due date and source `intake:<case>:<kind>`. The obligation SHALL block what the case type declares for that kind, and the closing statuses when the case type does not declare the kind. A second receipt of the same case MUST NOT place a second obligation.

#### Scenario: A request that needs a second signer
- **GIVEN** a case type whose obligation kind `cosign` is titled "the second signature"
- **WHEN** the portal opens a case with an `intakeObligations` entry of kind `cosign` for Henk de Vries, due 22 October 2026
- **THEN** the case carries an open obligation "the second signature" for Henk de Vries, due 22 October 2026, and the case cannot be closed while it is open

#### Scenario: The same case received twice
- **WHEN** the created event and the activation of the same case both arrive
- **THEN** the case carries one obligation for the entry

### Requirement: The holder settles an intake obligation from the portal

`POST /api/portal/obligations/settle` SHALL accept a request carrying portaliq's signed `X-Portal-Subject` assertion and `case`, `kind` and `answer`. Answer `sign` SHALL meet the obligation and record the assertion's subject and level of assurance on it; the case's entry SHALL read `signed`. A request without a valid assertion MUST be refused with 401. The case's own portal subject MUST NOT settle it (403), and an entry that is no longer awaiting MUST NOT be answered again (409).

#### Scenario: Henk signs
- **WHEN** Henk signs in on the portal and signs Sanne's request
- **THEN** the obligation is met with Henk's portal subject and level of assurance, and the case's entry reads `signed`

#### Scenario: Sanne tries to sign her own request
- **WHEN** the assertion's subject is the case's portal subject
- **THEN** dossiq answers 403 and nothing changes

### Requirement: A refused or lapsed intake obligation follows its kind's rule

A case type's obligation kind SHALL declare `whenUnmet`: `stay` (the default) keeps the obligation open and blocking, `withdraw` withdraws it with the reason, releasing the case. Answer `refuse` SHALL apply that rule and mark the entry `refused`. A daily job SHALL mark each awaiting entry whose due date has passed `expired` and apply the same rule.

#### Scenario: Nobody signs in time
- **GIVEN** the kind declares `whenUnmet: stay`
- **WHEN** the daily job runs on 23 October 2026 and the entry was due 22 October 2026
- **THEN** the entry reads `expired` and the obligation stays open, so a handler decides what the case does next

#### Scenario: The second signer refuses
- **GIVEN** the kind declares `whenUnmet: withdraw`
- **WHEN** Henk chooses to refuse
- **THEN** the entry reads `refused` and the obligation is withdrawn with the reason
