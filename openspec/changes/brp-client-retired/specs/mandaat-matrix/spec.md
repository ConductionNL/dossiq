# mandaat-matrix (delta)

@e2e exclude The relation step runs server-side on a decision and has no screen of its own; the read crosses to integriq and a registry. Proven by PHPUnit (ConflictOfInterestServiceTest, PersonRelationReaderTest, MandaatCheckServiceTest).

## MODIFIED Requirements

### Requirement: Conflict of Interest Detection

The `ConflictOfInterestService` SHALL detect when a decision-maker is related to the applicant and
SHALL block such decisions. It SHALL read the decision-maker's partner, parents and children from
the population register through integriq, reached by class name at runtime, and SHALL hold no
registry client of its own. The answer of the check SHALL say what the relation step did:

- When the read answers and names the applicant, the check SHALL return a conflict whose reason is
  the relation (`partner`, `parent` or `child`).
- When the read answers and does not name the applicant, the check SHALL return no conflict with
  `relationCheck: checked`.
- When the read is not there to be asked (integriq is not installed, integriq has no relation read,
  or integriq's BRP source does not allow relation fields), the check SHALL return no conflict with
  `relationCheck: not-available` and the reason. It SHALL NOT return an answer that reads like a
  relation step that ran.
- When the read is allowed and gives no answer, the check SHALL return a conflict with the reason
  `relatie_onbepaald`.

`isAuthorized()` SHALL carry `relationCheck` and its reason on every verdict it gives after the
check. No burgerservicenummer SHALL be logged, and numbers SHALL be compared as hashes.

#### Scenario: Automatic BRP conflict blocks the decision

- GIVEN a zaak whose applicant BSN is related (e.g. spouse) to the deciding user
- AND integriq's BRP source allows relation fields
- WHEN the user attempts a decision on this zaak
- THEN `checkConflict(userId, zaakId)` SHALL return `{conflict: true}` with the relation as reason
- AND `isAuthorized()` SHALL return `{authorized: false, reden: "belangenconflict"}`
- AND an escalation to a different role holder SHALL be triggered

#### Scenario: Without integriq the relation step says so

- GIVEN an instance without integriq, or with a BRP source that does not allow relation fields
- WHEN the user attempts a decision on a zaak with a natural-person applicant
- THEN `checkConflict()` SHALL return `{conflict: false, relationCheck: "not-available"}` with the reason
- AND `isAuthorized()` SHALL carry `relationCheck: "not-available"` on its verdict
- AND no request SHALL be made to the population register

#### Scenario: A registry that does not answer blocks

- GIVEN integriq's BRP source allows relation fields
- AND the registry refuses, fails or answers without the person
- WHEN the user attempts a decision on this zaak
- THEN `checkConflict()` SHALL return `{conflict: true, reason: "relatie_onbepaald"}`

#### Scenario: Manual conflict registration blocks the decision

- GIVEN a user with no automatic conflict who registers one with a reason
- WHEN they submit "Register interest conflict"
- THEN the case SHALL record the conflict flag and reason
- AND the user SHALL be prevented from executing the decision
- AND an escalation to an alternative mandaathouder SHALL be triggered
