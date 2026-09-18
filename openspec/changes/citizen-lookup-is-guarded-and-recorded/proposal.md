---
kind: config
depends_on: [sensitive-fields-declared]
---

# Proposal: citizen-lookup-is-guarded-and-recorded

Opened 2026-09-18 out of what `sensitive-fields-declared` measured rather than
out of the gap register. That change's tasks 1.3 and 1.4 described a
`CitizenLookupGuard` with a field check and a rate limit, and a
`sociaalDomeinAuditLog` that recorded reveals. Reading the code, none of the
three existed. The tasks were corrected to say so and the work was left
undone, which is this change.

## Why

`GET /apps/dossiq/api/kcc/voorblad?burgerId=` and
`GET /apps/dossiq/api/kcc/contactmomenten?burgerId=` take a raw citizen
identifier off the query string and answer that citizen's open cases and
contact history. `CitizenLookupGuard` asks one question about the caller,
once: are you in `kcc`, `klantcontact`, `beheerders` or an administrator. It
is the fix for PROC-IDOR-01 and it holds. Three things it does not do:

- **It is all or nothing.** A member of `kcc` receives every field of every
  contact moment, including `callerIdentification`, the free-text `summary`
  and the `transcript` of the call. The guard's own class comment names those
  as what it protects. It does not protect them: it decides whether the
  endpoint answers at all.
- **Nothing limits the rate.** The guard refuses the population walk to an
  outsider and permits it to a member. One `kcc` account can iterate
  BSN-shaped identifiers at whatever rate the instance serves, and the only
  trace is the web server log.
- **Nothing records the lookup.** `sociaalDomeinAuditLog`
  (`register.d/50-sociaal-domein.json`) declares `employeeId`, `moment`,
  `ipAddress`, `geraadpleegdeVelden`, `authorisationGround` and `result`, and
  has no writer anywhere in the app. `src/data/capabilityComparison.json`
  already says so twice. A reveal and a refusal look the same afterwards:
  like nothing.

The third is the one that matters most. A rate limit tells you somebody is
enumerating; a log tells you who, when and about whom, which is what an
inzageverzoek under AVG art. 15 and an FG audit ask for.

## What changes

- The sensitive fields of a contact moment are withheld from a caller who is
  not in `dossiq-sensitive`: `callerIdentification`,
  `geidentificeerdeBurgerId`, `summary` and `transcript`. Declared on the
  schema, so a direct OpenRegister read is refused, AND applied to the
  lookup responses, because dossiq re-shapes those and they never pass a
  property filter.
- Every lookup endpoint carries `#[UserRateLimit]`. Sixty in an hour, which
  no call handler reaches and no enumeration survives.
- Every lookup writes one `sociaalDomeinAuditLog` row, the refusals included.
  `sociaalDomeinAuditLog` gains `subjectId` and loses `caseId` from its
  `required` list, because a lookup is about a citizen and not about one case.
- `CitizenLookupGuard`'s class comment stops claiming what it does not do and
  points at the two classes that do.

## Ownership

All three halves are dossiq's, and the check was made rather than assumed:

- The rate limit is `OCP\AppFramework\Http\Attribute\UserRateLimit`, a
  Nextcloud framework attribute on a dossiq controller. OpenRegister has no
  say in how often a dossiq endpoint answers.
- The audit sink is `sociaalDomeinAuditLog`, a schema dossiq declares in its
  own register fragment. OpenRegister's field-access audit covers the direct
  object read, which `sensitive-fields-declared` already consumes; it cannot
  see a dossiq endpoint that composes a response of its own.
- The field declaration is OpenRegister's vocabulary, consumed, not rebuilt.

So no openregister half, and no openregister PR.

## ADRs

- Company ADR-023 rule 1: the declaration is OpenRegister's vocabulary.
- Company ADR-005 rule 3: an endpoint taking an identifier off the query
  string is guarded per caller, not per object owner.
- Company ADR-047: the AVG audit is a record, not a feature flag.

## Capabilities

- Modified: `security-hardening`: a citizen lookup is limited, recorded, and
  answers only the fields the caller may read.

## Impact

`lib/Service/CitizenLookupGuard.php`; new
`lib/Service/Kcc/CitizenLookupRecorder.php`;
`lib/Controller/ContactMomentController.php`;
`lib/Settings/register.d/40-kcc-werkplek.json` and
`register.d/50-sociaal-domein.json` and `dossiq_mock_register.json`; unit
tests; one e2e spec.
