# Tasks: citizen-lookup-is-guarded-and-recorded

Tier: V1. Kind: config. Opened out of `sensitive-fields-declared` tasks 1.3
and 1.4, which described three things that did not exist.

## 1. The fields

- [x] 1.1 `register.d/40-kcc-werkplek.json` and `dossiq_mock_register.json`:
  `authorization.read` naming `dossiq-sensitive` on `contactmoment`'s
  `callerIdentification`, `geidentificeerdeBurgerId`, `summary` and
  `transcript`. Version moves, or OpenRegister fast-skips the schema and the
  rules are never imported.
  - `@spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md`
- [x] 1.2 `CitizenLookupGuard::redactForCaller()`: remove those four keys from
  a lookup payload unless the caller holds `dossiq-sensitive`. One constant,
  one group, removal only. Applied in `ContactMomentController` to both
  `index` and `voorblad`.
  - unit: the four go for a non-member, stay for a member, and the rest of the
    payload is untouched in both cases

## 2. The rate limit

- [x] 2.1 `#[UserRateLimit(limit: 60, period: 3600)]` on `index`, `voorblad`
  and `create` in `ContactMomentController`.
  - unit: every method that takes a citizen identifier carries it
  - **FIVE, NOT THREE.** `CitizenLookupRateLimitTest::testNoLookupEndpointEscapedTheList`
    derives the list from the source instead of trusting the one above, and
    found `nieuweZaak` and `klachtRegistreren`: both take a caller-supplied
    `burgerId`, both ask the guard, and neither was named anywhere in this
    change's own proposal. They carry the limit and record their refusals now.
    This is exactly why the sweep derives rather than enumerates: a test naming
    three methods stays green through the two it never heard of.

## 3. The record

- [x] 3.1 `register.d/50-sociaal-domein.json` and the mock:
  `sociaalDomeinAuditLog` gains `subjectId` and drops `caseId` from
  `required`; version 1.0.0 to 1.1.0.
- [x] 3.2 `lib/Service/Kcc/CitizenLookupRecorder.php`: one row per attempt,
  refusals included, swallowing its own failures.
  - unit: a permitted lookup, a refused lookup, and a sink that throws

## 4. The guard's own claim

- [x] 4.1 `CitizenLookupGuard`: the class comment stops claiming a field
  check and names the recorder and the rate limit.
  - the existing `SensitiveFieldsDeclaredTest::testTheGuardDoesNotDecideAboutAField`
    still passes, which is what says the redaction did not creep into it

## 5. Verification

- [x] 5.1 `tests/e2e/citizen-lookup.spec.ts`: three principals, per D-5.
- [x] 5.2 `openspec validate citizen-lookup-is-guarded-and-recorded --strict`.
